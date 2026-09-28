<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AbuseProtection\BruteForceCounter;
use Quantum\Auth\AbuseProtection\CredentialStuffingBloomFilter;
use Quantum\Auth\AbuseProtection\ThrottleDecision;
use Quantum\Auth\AbuseProtection\ThrottleEngineV1;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;

final class BloqueBTest extends TestCase
{
    public function test_brute_force_counter_1m_window_increments_count(): void
    {
        $counter = new BruteForceCounter();
        $now = time();
        $key = 'identifier:testuser@test';

        for ($i = 0; $i < 3; $i++) {
            $counter->increment($key, $now);
        }

        $counts = $counter->currentCounts($key, $now);
        self::assertSame(3, $counts['1m']);
        self::assertSame(3, $counts['5m']);
        self::assertSame(3, $counts['15m']);
    }

    public function test_brute_force_counter_1m_window_expires_old_entries_correctly(): void
    {
        $counter = new BruteForceCounter();
        $now = time();

        $key = 'identifier:expiry@test';
        $counter->increment($key, $now - 120);
        $counter->increment($key, $now - 120);
        $counter->increment($key, $now - 10);

        $counts = $counter->currentCounts($key, $now);
        self::assertSame(1, $counts['1m']);
        self::assertSame(3, $counts['5m']);
        self::assertSame(3, $counts['15m']);
    }

    public function test_brute_force_counter_5m_cumulative_across_multiple_increments(): void
    {
        $counter = new BruteForceCounter();
        $now = time();
        $key = 'identifier:cumulative@test';

        for ($i = 0; $i < 2; $i++) {
            $counter->increment($key, $now - 240);
        }
        for ($i = 0; $i < 3; $i++) {
            $counter->increment($key, $now - 30);
        }

        $counts = $counter->currentCounts($key, $now);
        self::assertSame(3, $counts['1m']);
        self::assertSame(5, $counts['5m']);
        self::assertSame(5, $counts['15m']);
    }

    public function test_bloom_filter_matches_known_compromised_password_as_stuffing_flag(): void
    {
        $bloom = new CredentialStuffingBloomFilter();
        self::assertTrue($bloom->isProbablyCompromised('password'));
        self::assertTrue($bloom->isProbablyCompromised('123456'));
        self::assertTrue($bloom->isProbablyCompromised('admin'));
    }

    public function test_bloom_filter_allows_unknown_complex_password(): void
    {
        $bloom = new CredentialStuffingBloomFilter();
        self::assertFalse($bloom->isProbablyCompromised('N0t-A-C0mm0n-P@ss-2025-XyZ!'));
        self::assertFalse($bloom->isProbablyCompromised('Unique-Ph-R4s3-Here-' . bin2hex(random_bytes(4))));
    }

    public function test_throttle_engine_allows_baseline_clean_user(): void
    {
        $counter = new BruteForceCounter();
        $bloom = new CredentialStuffingBloomFilter();
        $engine = new ThrottleEngineV1($counter, $bloom);

        $decision = $engine->decide(
            identifier: 'clean_user@test',
            deviceRef: 'dev_clean_01',
            ipPrefix: '192.168.1',
            rawPassword: 'My-Safe-Pass-2025-Xz!',
        );

        self::assertTrue($decision->isAllowed());
        self::assertNull($decision->reasonCode);
        self::assertSame(0, $decision->retryAfterSeconds);
    }

    public function test_throttle_engine_denies_after_brute_threshold_breached(): void
    {
        $counter = new BruteForceCounter();
        $bloom = new CredentialStuffingBloomFilter();
        $engine = new ThrottleEngineV1($counter, $bloom, thresholds: ['1m' => 3, '5m' => 5, '15m' => 10]);
        $now = time();

        $key = 'identifier:brute_target@test';
        for ($i = 0; $i < 4; $i++) {
            $counter->increment($key, $now);
        }

        $decision = $engine->decide(identifier: 'brute_target@test');
        self::assertTrue($decision->isDenied());
        self::assertSame('brute_threshold_1m', $decision->reasonCode);
        self::assertGreaterThanOrEqual(60, $decision->retryAfterSeconds);
    }

    public function test_throttle_engine_denies_credential_stuffing_combined_with_repeated_attempts(): void
    {
        $counter = new BruteForceCounter();
        $bloom = new CredentialStuffingBloomFilter();
        $engine = new ThrottleEngineV1($counter, $bloom);
        $now = time();

        $key = 'identifier:stuffing@test';
        $counter->increment($key, $now - 180);
        $counter->increment($key, $now - 60);

        $decision = $engine->decide(
            identifier: 'stuffing@test',
            rawPassword: 'password',
        );

        self::assertTrue($decision->isDenied());
        $reasons = explode('|', (string) $decision->reasonCode);
        self::assertContains('credential_stuffing', $reasons);
    }

    public function test_throttle_decision_retry_after_seconds_is_correct_for_5m_window(): void
    {
        $counter = new BruteForceCounter();
        $bloom = new CredentialStuffingBloomFilter();
        $engine = new ThrottleEngineV1($counter, $bloom, thresholds: ['1m' => 100, '5m' => 2, '15m' => 100]);
        $now = time();

        $key = 'identifier:retry@test';
        $counter->increment($key, $now - 120);
        $counter->increment($key, $now - 10);

        $decision = $engine->decide(identifier: 'retry@test');
        self::assertTrue($decision->isDenied());
        self::assertSame(300, $decision->retryAfterSeconds);
    }

    public function test_orchestrator_without_throttle_injected_stays_baseline_compat_no_op(): void
    {
        $allowAuthenticator = new class implements AuthenticatorInterface {
            public function supports(AuthenticationOperationContext $context): bool
            {
                return true;
            }

            public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
            {
                return AuthenticationDecision::unauthenticated(['authenticator' => 'noop_auth']);
            }
        };

        $resolver = $this->createMock(AuthenticatorResolverInterface::class);
        $resolver->method('resolve')->willReturn([$allowAuthenticator]);

        $orchestrator = new AuthenticationOrchestrator($resolver);

        $request = new AuthenticationRequest(
            requestId: 'throttle-noop-' . bin2hex(random_bytes(4)),
            transport: 'runtime',
            attributes: [
                'credentials' => ['identifier' => 'clean@test', 'password' => 'Valid123!'],
            ],
        );
        $ctx = new AuthenticationOperationContext('authenticate', $request);
        $decision = $orchestrator->execute($ctx);

        self::assertSame(AuthenticationDecisionStatus::Unauthenticated, $decision->status);
        self::assertArrayNotHasKey('retry_after_seconds', $decision->metadata);
        self::assertArrayNotHasKey('throttle_reason', $decision->metadata);
    }
}
