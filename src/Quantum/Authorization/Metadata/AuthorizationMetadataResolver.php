<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\ControllerMethodSubject;
use Quantum\Metadata\Subjects\RouteMatchSubject;
use Quantum\Routing\RouteMatch;

final readonly class AuthorizationMetadataResolver implements AuthorizationMetadataResolverInterface
{
    public function __construct(
        private MetadataEngineInterface $metadata,
        private ?AuthorizationManifestStoreInterface $manifestStore = null,
    ) {}

    public function resolve(RouteMatch $match, ?ControllerDefinition $definition = null): AuthorizationMetadata
    {
        $subject = new RouteMatchSubject($match);

        if ($definition !== null) {
            [$controllerClass, $method] = $this->controllerParts($definition);

            if ($controllerClass !== null && class_exists($controllerClass)) {
                $classSubject = new ControllerClassSubject($controllerClass, $subject);
                $subject = $method !== null && method_exists($controllerClass, $method)
                    ? new ControllerMethodSubject($controllerClass, $method, $classSubject)
                    : $classSubject;
            }
        }

        $manifestKey = $this->manifestKey($match, $definition);
        $cached = $manifestKey !== null ? $this->manifestStore?->get($manifestKey) : null;

        if ($cached instanceof AuthorizationMetadataPayload) {
            return new AuthorizationMetadata(
                public: $cached->public(),
                requirements: $cached->requirements(),
                fingerprint: $cached->fingerprint(),
            );
        }

        $bag = $this->metadata->resolve(new MetadataRequest(
            subject: $subject,
            keys: ['authorization.public', 'authorization.requirements'],
        ));

        $requirements = [];
        $rawRequirements = $bag->get('authorization.requirements', []);

        if (is_array($rawRequirements)) {
            foreach ($rawRequirements as $requirement) {
                if (! is_array($requirement) || ! isset($requirement['ability']) || ! is_string($requirement['ability'])) {
                    continue;
                }

                $ability = trim($requirement['ability']);

                if ($ability === '') {
                    continue;
                }

                $requirements[] = new AuthorizationRequirement(
                    ability: $ability,
                    subject: $requirement['subject'] ?? null,
                    source: is_string($requirement['source'] ?? null) ? $requirement['source'] : 'metadata',
                    condition: $requirement['condition'] ?? null,
                );
            }
        }

        $payload = new AuthorizationMetadataPayload(
            public: (bool) $bag->get('authorization.public', false),
            requirements: $requirements,
            fingerprint: $manifestKey,
        );

        if ($this->manifestStore !== null) {
            $this->manifestStore->put($payload);
        }

        return new AuthorizationMetadata(
            public: $payload->public(),
            requirements: $payload->requirements(),
            fingerprint: $payload->fingerprint(),
        );
    }

    private function manifestKey(RouteMatch $match, ?ControllerDefinition $definition): ?string
    {
        $route = $match->route();
        $method = $match->resolvedMethod();
        $parameters = $match->parameters();
        ksort($parameters);

        $components = [
            'route_path' => $route->path(),
            'route_methods' => $route->methods(),
            'match_method' => $method,
            'controller_definition' => $this->controllerDefinitionFingerprint($definition),
            'parameters_keys' => array_keys($parameters),
        ];

        try {
            return sha1(json_encode($components, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    private function controllerDefinitionFingerprint(?ControllerDefinition $definition): string
    {
        if ($definition === null) {
            return 'none';
        }

        [$controllerClass, $method] = $this->controllerParts($definition);

        if ($controllerClass === null) {
            return 'inline';
        }

        $method ??= '__invoke';

        return $controllerClass . '::' . $method;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function controllerParts(ControllerDefinition $definition): array
    {
        $action = $definition->action();

        if (is_string($action) && str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);

            return [$controllerClass, $method];
        }

        if (is_string($action)) {
            return [$action, '__invoke'];
        }

        if (is_array($action) && isset($action[0], $action[1])) {
            $controllerClass = is_object($action[0]) ? get_class($action[0]) : (string) $action[0];

            return [$controllerClass, (string) $action[1]];
        }

        if (is_object($action) && ! $action instanceof \Closure) {
            return [get_class($action), method_exists($action, '__invoke') ? '__invoke' : null];
        }

        return [null, null];
    }
}
