<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
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
                );
            }
        }

        return new AuthorizationMetadata(
            public: (bool) $bag->get('authorization.public', false),
            requirements: $requirements,
        );
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
