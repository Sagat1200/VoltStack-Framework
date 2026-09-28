<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

use Quantum\Auth\Contracts\AbuseProtectionThrottleInterface;

final class ThrottleEngineV1 implements AbuseProtectionThrottleInterface
{
    public function __construct(
        private readonly BruteForceCounter $bruteForceCounter,
        private readonly CredentialStuffingBloomFilter $bloomFilter,
        /** @var array{1m: int, 5m: int, 15m: int} $thresholds */
        private readonly array $thresholds = ['1m' => 5, '5m' => 10, '15m' => 20],
        private readonly int $retryAfterSeconds = 60,
    ) {
    }

    public function decide(
        string $identifier,
        ?string $deviceRef = null,
        ?string $ipPrefix = null,
        ?string $rawPassword = null,
    ): ThrottleDecision {
        $idKey = 'identifier:' . $identifier;
        $countsId = $this->bruteForceCounter->currentCounts($idKey);
        $denyReasons = [];
        $retryWindow = 0;

        foreach (['1m', '5m', '15m'] as $window) {
            if ($countsId[$window] >= $this->thresholds[$window]) {
                $denyReasons[] = 'brute_threshold_' . $window;
                $windowSecs = $window === '1m' ? 60 : ($window === '5m' ? 300 : 900);
                $retryWindow = max($retryWindow, $windowSecs);
            }
        }

        $deviceCounts = null;
        if ($deviceRef !== null && $deviceRef !== '') {
            $deviceKey = 'device:' . $deviceRef;
            $deviceCounts = $this->bruteForceCounter->currentCounts($deviceKey);
            foreach (['1m', '5m', '15m'] as $window) {
                if ($deviceCounts[$window] >= (int) ceil($this->thresholds[$window] * 1.5)) {
                    $denyReasons[] = 'brute_device_' . $window;
                    $windowSecs = $window === '1m' ? 60 : ($window === '5m' ? 300 : 900);
                    $retryWindow = max($retryWindow, $windowSecs);
                }
            }
        }

        $ipCounts = null;
        if ($ipPrefix !== null && $ipPrefix !== '') {
            $ipKey = 'ip:' . $ipPrefix;
            $ipCounts = $this->bruteForceCounter->currentCounts($ipKey);
            foreach (['1m', '5m', '15m'] as $window) {
                if ($ipCounts[$window] >= (int) ceil($this->thresholds[$window] * 2)) {
                    $denyReasons[] = 'brute_ip_' . $window;
                    $windowSecs = $window === '1m' ? 60 : ($window === '5m' ? 300 : 900);
                    $retryWindow = max($retryWindow, $windowSecs);
                }
            }
        }

        $stuffingDetected = false;
        if ($rawPassword !== null && $rawPassword !== '') {
            $stuffingDetected = $this->bloomFilter->isProbablyCompromised($rawPassword);
            if ($stuffingDetected && $countsId['5m'] >= 2) {
                $denyReasons[] = 'credential_stuffing';
                $retryWindow = max($retryWindow, $this->retryAfterSeconds);
            }
        }

        $metadata = [
            'counts_identifier' => $countsId,
            'thresholds' => $this->thresholds,
        ];
        if ($deviceCounts !== null) {
            $metadata['counts_device'] = $deviceCounts;
        }
        if ($ipCounts !== null) {
            $metadata['counts_ip'] = $ipCounts;
        }
        if ($rawPassword !== null) {
            $metadata['stuffing_flagged'] = $stuffingDetected;
        }

        if ($denyReasons !== []) {
            return ThrottleDecision::deny(
                reasonCode: implode('|', $denyReasons),
                retryAfterSeconds: $retryWindow > 0 ? $retryWindow : $this->retryAfterSeconds,
                metadata: array_merge($metadata, ['deny_reasons' => $denyReasons]),
            );
        }

        return ThrottleDecision::allow($metadata);
    }

    public function recordAttempt(
        string $identifier,
        bool $wasSuccessful,
        ?string $deviceRef = null,
        ?string $ipPrefix = null,
    ): void {
        if (! $wasSuccessful) {
            $this->bruteForceCounter->increment('identifier:' . $identifier);
            if ($deviceRef !== null && $deviceRef !== '') {
                $this->bruteForceCounter->increment('device:' . $deviceRef);
            }
            if ($ipPrefix !== null && $ipPrefix !== '') {
                $this->bruteForceCounter->increment('ip:' . $ipPrefix);
            }
        } else {
            $this->bruteForceCounter->reset('identifier:' . $identifier);
            if ($deviceRef !== null && $deviceRef !== '') {
                $this->bruteForceCounter->reset('device:' . $deviceRef);
            }
            if ($ipPrefix !== null && $ipPrefix !== '') {
                $this->bruteForceCounter->reset('ip:' . $ipPrefix);
            }
        }
    }
}
