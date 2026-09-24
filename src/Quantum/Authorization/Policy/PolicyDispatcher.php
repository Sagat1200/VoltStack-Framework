<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Policy\Attributes\HandlesAbility;
use Quantum\Authorization\Policy\Contracts\PolicyInterface;
use Quantum\Authorization\Policy\Contracts\SupportsAuthorizationRequest;
use Quantum\Authorization\Subject\SubjectDescriptor;
use ReflectionAttribute;
use ReflectionMethod;

final class PolicyDispatcher
{
    public function dispatch(object $policy, AuthorizationRequest $request): DecisionResult
    {
        if ($policy instanceof SupportsAuthorizationRequest && ! $policy->supports($request)) {
            return DecisionResult::abstain(
                source: $policy::class,
                reasonCode: 'policy_not_applicable',
            );
        }

        if ($policy instanceof PolicyInterface) {
            return $policy->evaluate($request);
        }

        $before = $this->invokeIfPresent($policy, 'before', $request);

        if ($before instanceof DecisionResult) {
            return $before;
        }

        $method = $this->policyMethod($policy, $request->ability()->name());

        if ($method === null || ! method_exists($policy, $method)) {
            return DecisionResult::abstain(
                source: $policy::class,
                reasonCode: 'policy_method_not_found',
                metadata: ['method' => $method ?? $this->defaultPolicyMethod($request->ability()->name())],
            );
        }

        return $this->normalize(
            $this->invokeMethod($policy, $method, $request),
            $policy::class,
            'policy_return',
        );
    }

    private function invokeIfPresent(object $policy, string $method, AuthorizationRequest $request): ?DecisionResult
    {
        if (! method_exists($policy, $method)) {
            return null;
        }

        return $this->normalize(
            $this->invokeMethod($policy, $method, $request),
            $policy::class,
            'policy_before',
        );
    }

    private function invokeMethod(object $policy, string $method, AuthorizationRequest $request): mixed
    {
        $reflection = new ReflectionMethod($policy, $method);

        return $policy->{$method}(...$this->argumentsFor($reflection, $request));
    }

    private function policyMethod(object $policy, string $ability): ?string
    {
        foreach ((new \ReflectionObject($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(HandlesAbility::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $instance = $attribute->newInstance();

                foreach ((array) $instance->abilities as $handled) {
                    if (is_string($handled) && trim($handled) === $ability) {
                        return $method->getName();
                    }
                }
            }
        }

        $default = $this->defaultPolicyMethod($ability);

        return method_exists($policy, $default)
            ? $default
            : null;
    }

    private function defaultPolicyMethod(string $ability): string
    {
        $segments = preg_split('/[^a-zA-Z0-9]+/', $ability) ?: [];
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return 'handle';
        }

        $method = array_shift($segments);

        foreach ($segments as $segment) {
            $method .= ucfirst($segment);
        }

        return $method;
    }

    /**
     * @return list<mixed>
     */
    private function argumentsFor(ReflectionMethod $method, AuthorizationRequest $request): array
    {
        $resolved = [];
        $fallback = [
            $request->principal(),
            $request->subject()->value(),
            $request->context(),
            $request,
        ];
        $fallbackIndex = 0;

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $typeName = $type->getName();

                $value = match (true) {
                    is_a($typeName, Ability::class, true) => $request->ability(),
                    is_a($typeName, AuthorizationContext::class, true) => $request->context(),
                    is_a($typeName, AuthorizationRequest::class, true) => $request,
                    is_a($typeName, SubjectDescriptor::class, true) => $request->subject(),
                    is_a($typeName, PrincipalInterface::class, true) => $request->principal(),
                    $request->subject()->value() !== null && is_a($request->subject()->className() ?? '', $typeName, true) => $request->subject()->value(),
                    default => null,
                };

                if ($value !== null || $parameter->allowsNull()) {
                    $resolved[] = $value;
                    continue;
                }
            }

            if ($parameter->getName() === 'ability') {
                $resolved[] = $request->ability()->name();
                continue;
            }

            if ($parameter->getName() === 'request') {
                $resolved[] = $request;
                continue;
            }

            if ($parameter->getName() === 'context') {
                $resolved[] = $request->context();
                continue;
            }

            if ($fallbackIndex < count($fallback)) {
                $resolved[] = $fallback[$fallbackIndex];
                $fallbackIndex++;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $resolved[] = $parameter->getDefaultValue();
                continue;
            }

            $resolved[] = null;
        }

        return $resolved;
    }

    private function normalize(mixed $value, string $source, string $fallbackReasonCode): DecisionResult
    {
        if ($value instanceof DecisionResult) {
            return $value;
        }

        if ($value === null) {
            return DecisionResult::abstain($source, $fallbackReasonCode);
        }

        if (is_bool($value)) {
            return $value
                ? DecisionResult::allow($source, 'explicit_allow')
                : DecisionResult::deny($source, 'explicit_deny');
        }

        throw new \UnexpectedValueException(sprintf(
            'Authorization policy [%s] returned an unsupported result of type [%s].',
            $source,
            get_debug_type($value),
        ));
    }
}
