<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

use Quantum\Auth\Contracts\RiskSignalProviderInterface;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class ImpossibleTravelVelocitySignal implements RiskSignalProviderInterface
{
    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
    {
        $reasonCodes = [];
        $score = 0;
        $metadata = [];

        $nowTs = time();
        $lastAuthAt = null;
        $lastTzHint = null;
        $currentTzHint = $request->attributes['tz_hint'] ?? null;

        if ($context !== null) {
            $identityAttrs = $context->identity->attributes ?? [];
            if (is_array($identityAttrs)) {
                if (isset($identityAttrs['last_auth_at']) && is_int($identityAttrs['last_auth_at'])) {
                    $lastAuthAt = $identityAttrs['last_auth_at'];
                }
                if (isset($identityAttrs['last_tz_hint']) && is_string($identityAttrs['last_tz_hint'])) {
                    $lastTzHint = $identityAttrs['last_tz_hint'];
                }
            }
        }

        $deltaHours = null;
        if (is_int($lastAuthAt)) {
            $deltaSecs = $nowTs - $lastAuthAt;
            $deltaHours = (int) floor($deltaSecs / 3600);
        }

        $tzDiff = null;
        if (is_string($currentTzHint) && is_string($lastTzHint) && $currentTzHint !== '' && $lastTzHint !== '') {
            $tzDiff = abs((int) $currentTzHint - (int) $lastTzHint);
        }

        $impossibleFlag = is_int($deltaHours) && $deltaHours < 2 && $deltaHours >= 0 && is_int($tzDiff) && $tzDiff >= 2;

        if ($impossibleFlag) {
            $score += 40;
            $reasonCodes[] = 'impossible_travel_velocity';
        }

        $metadata['last_auth_at'] = $lastAuthAt;
        $metadata['delta_hours_since_last_auth'] = $deltaHours;
        $metadata['current_tz_hint'] = $currentTzHint;
        $metadata['last_tz_hint'] = $lastTzHint;
        $metadata['tz_diff_hours'] = $tzDiff;
        $metadata['impossible_travel_flag'] = $impossibleFlag;

        return RiskScore::fromScore($score, $reasonCodes, $metadata);
    }
}
