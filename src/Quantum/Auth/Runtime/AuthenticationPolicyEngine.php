<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Identity\IdentityInterface;

final class AuthenticationPolicyEngine
{
    /**
     * @var array<int, AuthenticationPolicyRuleInterface>
     */
    private array $rules = [];

    /**
     * @param iterable<array-key, AuthenticationPolicyRuleInterface> $rules
     */
    public function __construct(
        iterable $rules = [],
    ) {
        foreach ($rules as $rule) {
            if ($rule instanceof AuthenticationPolicyRuleInterface) {
                $this->rules[] = $rule;
            }
        }
    }

    public function addRule(AuthenticationPolicyRuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    /**
     * @return array<int, AuthenticationPolicyRuleInterface>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(
        string $operation,
        ?IdentityInterface $identity = null,
        array $context = [],
    ): PolicyDecision {
        $applied = 0;
        $collectedReasons = [];
        $collectedMetadata = [];
        $allowedReasons = [];

        foreach ($this->rules as $rule) {
            if (! $rule->applies($operation, $identity, $context)) {
                continue;
            }

            $applied++;
            $decision = $rule->evaluate($operation, $identity, $context);

            foreach ($decision->reasonCodes as $code) {
                if ($decision->isDenied()) {
                    $collectedReasons[] = $code;
                } else {
                    $allowedReasons[] = $code;
                }
            }

            foreach ($decision->metadata as $key => $value) {
                if ($decision->isDenied()) {
                    $collectedMetadata[$key] = $value;
                }
            }

            if ($decision->isDenied()) {
                return PolicyDecision::deny(
                    $collectedReasons,
                    array_merge(
                        [
                            'operation' => $operation,
                            'rules_applied' => $applied,
                            'rules_total' => count($this->rules),
                        ],
                        $collectedMetadata,
                    ),
                );
            }
        }

        return PolicyDecision::allow(
            array_merge($allowedReasons, [$applied === 0 ? 'auth.policy.no_rules_applied' : 'auth.policy.all_rules_allowed']),
            [
                'operation' => $operation,
                'rules_applied' => $applied,
                'rules_total' => count($this->rules),
            ],
        );
    }
}
