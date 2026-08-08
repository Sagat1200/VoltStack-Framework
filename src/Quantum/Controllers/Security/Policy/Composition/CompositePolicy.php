<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy\Composition;

use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

abstract class CompositePolicy implements ControllerSecurityPolicyInterface
{
    /** @var array<ControllerSecurityPolicyInterface> */
    protected readonly array $children;

    /**
     * @param array<ControllerSecurityPolicyInterface> $children
     */
    public function __construct(
        protected readonly string $policyId,
        array $children,
    ) {
        $sanitized = [];
        foreach ($children as $child) {
            if (!$child instanceof ControllerSecurityPolicyInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'CompositePolicy children must implement %s; got %s',
                    ControllerSecurityPolicyInterface::class,
                    get_debug_type($child),
                ));
            }
            $sanitized[] = $child;
        }
        $this->children = $sanitized;
    }

    public function id(): string
    {
        return $this->policyId;
    }

    public function evaluate(SecurityEvaluationRequest $request): SecurityDecision
    {
        return $this->evaluateChildren($this->children, $request);
    }

    /**
     * @param array<ControllerSecurityPolicyInterface> $children
     */
    abstract protected function evaluateChildren(array $children, SecurityEvaluationRequest $request): SecurityDecision;
}
