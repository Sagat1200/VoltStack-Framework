<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

final class PolicyDispatcher
{
    public function dispatch(object $policy, AuthorizationRequest $request): DecisionResult
    {
        $before = $this->invokeIfPresent($policy, 'before', $request);

        if ($before instanceof DecisionResult) {
            return $before;
        }

        $method = $this->policyMethod($request->ability()->name());

        if (! method_exists($policy, $method)) {
            return DecisionResult::abstain(
                source: $policy::class,
                reasonCode: 'policy_method_not_found',
                metadata: ['method' => $method],
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
        $reflection = new \ReflectionMethod($policy, $method);
        $argumentPool = [
            $request->principal(),
            $request->subject()->value(),
            $request->context(),
            $request,
        ];

        return $policy->{$method}(...array_slice($argumentPool, 0, $reflection->getNumberOfParameters()));
    }

    private function policyMethod(string $ability): string
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
