<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;

final class TrustedDeviceEnrollmentLimitRule implements AuthenticationPolicyRuleInterface
{
    /**
     * @param int|null $maximumTrustedDevices
     * @param array<int, string> $operations
     */
    public function __construct(
        private readonly ?int $maximumTrustedDevices = null,
        private readonly TrustedDeviceRepositoryInterface $trustedDevices = new InMemoryTrustedDeviceRepository(),
        private readonly array $operations = ['managed_devices', 'enroll_trusted_device', 'revoke_managed_device'],
    ) {}

    public function applies(string $operation, ?IdentityInterface $identity = null, array $context = []): bool
    {
        if ($this->maximumTrustedDevices === null || $this->maximumTrustedDevices <= 0) {
            return false;
        }

        if ($identity === null) {
            return false;
        }

        return in_array($operation, $this->operations, true);
    }

    public function evaluate(string $operation, ?IdentityInterface $identity = null, array $context = []): PolicyDecision
    {
        if (! $this->applies($operation, $identity, $context)) {
            return PolicyDecision::allow(['tdv_enrollment_limit_rule_not_applicable']);
        }

        $now = isset($context['now']) && is_int($context['now']) ? $context['now'] : time();
        $currentCount = isset($context['current_trusted_device_count']) && is_int($context['current_trusted_device_count'])
            ? $context['current_trusted_device_count']
            : count($this->trustedDevices->listForIdentity($identity, $now));

        $denyWhen = match (true) {
            $operation === 'enroll_trusted_device' => $currentCount >= $this->maximumTrustedDevices,
            default => false,
        };

        if ($denyWhen) {
            return PolicyDecision::deny([
                'auth.policy.trusted_device_enrollment_limit_exceeded',
            ], [
                'current_trusted_device_count' => $currentCount,
                'maximum_trusted_devices' => $this->maximumTrustedDevices,
            ]);
        }

        return PolicyDecision::allow(['auth.policy.trusted_devices_within_limit'], [
            'current_trusted_device_count' => $currentCount,
            'maximum_trusted_devices' => $this->maximumTrustedDevices,
        ]);
    }
}
