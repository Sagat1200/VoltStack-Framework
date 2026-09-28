<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

use Quantum\Auth\Contracts\RiskSignalProviderInterface;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class IpDriftSignal implements RiskSignalProviderInterface
{
    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
    {
        $reasonCodes = [];
        $score = 0;
        $metadata = [];

        $currentIpPrefix = $request->attributes['ip_prefix'] ?? null;
        $lastKnownIpPrefix = null;

        if ($context !== null) {
            $identityAttrs = $context->identity->attributes ?? [];
            if (is_array($identityAttrs) && isset($identityAttrs['last_known_ip_prefix']) && is_string($identityAttrs['last_known_ip_prefix'])) {
                $lastKnownIpPrefix = $identityAttrs['last_known_ip_prefix'];
            }
        }

        $hasDrifted = is_string($currentIpPrefix) && $currentIpPrefix !== ''
            && is_string($lastKnownIpPrefix) && $lastKnownIpPrefix !== ''
            && $currentIpPrefix !== $lastKnownIpPrefix;

        if ($hasDrifted) {
            $score += 15;
            $reasonCodes[] = 'ip_prefix_drift';
        }

        $metadata['current_ip_prefix'] = $currentIpPrefix;
        $metadata['last_known_ip_prefix'] = $lastKnownIpPrefix;
        $metadata['has_ip_drift'] = $hasDrifted;

        return RiskScore::fromScore($score, $reasonCodes, $metadata);
    }
}
