<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

use Quantum\Auth\Contracts\RiskSignalProviderInterface;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class IrregularTimeSignal implements RiskSignalProviderInterface
{
    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
    {
        $reasonCodes = [];
        $score = 0;
        $metadata = [];

        $tzOffset = 0;
        $tzHint = $request->attributes['tz_hint'] ?? null;
        if (is_numeric($tzHint)) {
            $tzOffset = (int) $tzHint;
        }

        $nowUtc = time();
        $localHour = (int) gmdate('G', $nowUtc + ($tzOffset * 3600));
        $isIrregular = $localHour >= 2 && $localHour <= 5;

        if ($isIrregular) {
            $score += 10;
            $reasonCodes[] = 'irregular_auth_time_window';
        }

        $metadata['utc_hour'] = (int) gmdate('G', $nowUtc);
        $metadata['tz_offset_hours'] = $tzOffset;
        $metadata['local_hour'] = $localHour;
        $metadata['is_irregular_window'] = $isIrregular;

        return RiskScore::fromScore($score, $reasonCodes, $metadata);
    }
}
