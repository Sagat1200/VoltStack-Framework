<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

use Quantum\Auth\Contracts\RiskSignalProviderInterface;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class CompositeRiskSignalEngine
{
    /**
     * @param iterable<RiskSignalProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {
    }

    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
    {
        $aggregateScore = 0;
        $aggregateReasons = [];
        $signalDetails = [];

        foreach ($this->providers as $index => $provider) {
            $risk = $provider->evaluate($request, $context);
            $aggregateScore += $risk->score;
            foreach ($risk->reasonCodes as $code) {
                $aggregateReasons[] = $code;
            }
            $signalDetails[get_class($provider) . '#' . $index] = [
                'score' => $risk->score,
                'level' => $risk->level,
                'reason_codes' => $risk->reasonCodes,
            ];
        }

        $aggregateReasons = array_values(array_unique($aggregateReasons));

        return RiskScore::fromScore(
            rawScore: $aggregateScore,
            reasonCodes: $aggregateReasons,
            metadata: ['signals' => $signalDetails],
        );
    }
}
