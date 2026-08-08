<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;

final class PolicyBuilder
{
    /** @var list<array{kind:string,value:mixed}> */
    private array $stack = [];

    private static int $anonCounter = 0;

    private function __construct(
        private readonly ?PolicyExpressionResolver $resolver = null,
    ) {}

    public static function create(?PolicyExpressionResolver $resolver = null): self
    {
        return new self($resolver);
    }

    /**
     * @param iterable<ControllerSecurityPolicyInterface|string> $policies
     */
    public function allOf(iterable $policies, ?string $id = null): self
    {
        $children = $this->normalizePolicies($policies);
        $policyId = $id ?? sprintf('composite.all_of.%d', self::$anonCounter++);
        $this->stack[] = ['kind' => 'policy', 'value' => new AllOfPolicy($policyId, $children)];
        return $this;
    }

    /**
     * @param iterable<ControllerSecurityPolicyInterface|string> $policies
     */
    public function anyOf(iterable $policies, ?string $id = null): self
    {
        $children = $this->normalizePolicies($policies);
        $policyId = $id ?? sprintf('composite.any_of.%d', self::$anonCounter++);
        $this->stack[] = ['kind' => 'policy', 'value' => new AnyOfPolicy($policyId, $children)];
        return $this;
    }

    public function not(ControllerSecurityPolicyInterface|string $policy, ?string $id = null): self
    {
        $child = $this->normalizePolicy($policy);
        $policyId = $id ?? sprintf('composite.not.%d', self::$anonCounter++);
        $this->stack[] = ['kind' => 'policy', 'value' => new NotPolicy($policyId, [$child])];
        return $this;
    }

    /**
     * @param iterable<ControllerSecurityPolicyInterface|string> $policies
     */
    public function atLeastOne(iterable $policies, int $minimum = 1, ?string $id = null): self
    {
        $children = $this->normalizePolicies($policies);
        $policyId = $id ?? sprintf('composite.at_least_one.%d', self::$anonCounter++);
        $this->stack[] = ['kind' => 'policy', 'value' => new AtLeastOnePolicy($policyId, $children, $minimum)];
        return $this;
    }

    /**
     * @param iterable<ControllerSecurityPolicyInterface|string> $policies
     * @param array<string,int> $weights
     */
    public function weightedVoting(iterable $policies, array $weights = [], float $approvalRatio = 0.5, ?string $id = null): self
    {
        $children = $this->normalizePolicies($policies);
        $policyId = $id ?? sprintf('composite.weighted.%d', self::$anonCounter++);
        $this->stack[] = ['kind' => 'policy', 'value' => new WeightedVotingPolicy($policyId, $children, $weights, $approvalRatio)];
        return $this;
    }

    /**
     * Parse string expression: "role:admin && (scope:read || owner:true) && !guest"
     */
    public function parse(string $expression, ?string $id = null): self
    {
        $resolver = $this->resolver ?? PolicyExpressionResolver::default();
        $policy = $resolver->parse($expression, $id);
        $this->stack[] = ['kind' => 'policy', 'value' => $policy];
        return $this;
    }

    public function push(ControllerSecurityPolicyInterface $policy): self
    {
        $this->stack[] = ['kind' => 'policy', 'value' => $policy];
        return $this;
    }

    /**
     * @return array<ControllerSecurityPolicyInterface>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->stack as $entry) {
            if ($entry['kind'] === 'policy') {
                $out[] = $entry['value'];
            }
        }
        return $out;
    }

    public function last(): ?ControllerSecurityPolicyInterface
    {
        $l = $this->stack[count($this->stack) - 1] ?? null;
        if (!$l) return null;
        return $l['value'];
    }

    /**
     * @param iterable<ControllerSecurityPolicyInterface|string> $policies
     * @return array<ControllerSecurityPolicyInterface>
     */
    private function normalizePolicies(iterable $policies): array
    {
        $out = [];
        foreach ($policies as $p) {
            $out[] = $this->normalizePolicy($p);
        }
        return $out;
    }

    private function normalizePolicy(ControllerSecurityPolicyInterface|string $policy): ControllerSecurityPolicyInterface
    {
        if ($policy instanceof ControllerSecurityPolicyInterface) {
            return $policy;
        }
        $resolver = $this->resolver ?? PolicyExpressionResolver::default();
        return $resolver->resolveSingle($policy);
    }
}
