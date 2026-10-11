<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\RetryPolicy;
use Quantum\Database\Transaction\TransactionException;

final class DatabaseRetryPolicyTest extends TestCase
{
    public function test_defaults_are_sane(): void
    {
        $policy = RetryPolicy::defaults();

        self::assertSame(3, $policy->maxAttempts);
        self::assertSame(50, $policy->initialDelayMs);
        self::assertSame(1000, $policy->maxDelayMs);
        self::assertSame(2.0, $policy->backoffMultiplier);
        self::assertTrue($policy->jitter);
    }

    public function test_max_attempts_must_be_at_least_one(): void
    {
        $this->expectException(TransactionException::class);

        new RetryPolicy(maxAttempts: 0);
    }

    public function test_initial_delay_must_be_non_negative(): void
    {
        $this->expectException(TransactionException::class);

        new RetryPolicy(initialDelayMs: -1);
    }

    public function test_max_delay_must_be_at_least_initial_delay(): void
    {
        $this->expectException(TransactionException::class);

        new RetryPolicy(initialDelayMs: 100, maxDelayMs: 50);
    }

    public function test_backoff_multiplier_must_be_at_least_one(): void
    {
        $this->expectException(TransactionException::class);

        new RetryPolicy(backoffMultiplier: 0.5);
    }

    public function test_delay_for_attempt_one_equals_initial_delay(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            initialDelayMs: 50,
            maxDelayMs: 1000,
            backoffMultiplier: 2.0,
            jitter: false,
        );

        self::assertSame(50, $policy->delayForAttempt(1));
    }

    public function test_delay_grows_exponentially_without_jitter(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            initialDelayMs: 10,
            maxDelayMs: 10000,
            backoffMultiplier: 2.0,
            jitter: false,
        );

        self::assertSame(10, $policy->delayForAttempt(1));
        self::assertSame(20, $policy->delayForAttempt(2));
        self::assertSame(40, $policy->delayForAttempt(3));
        self::assertSame(80, $policy->delayForAttempt(4));
    }

    public function test_delay_is_capped_at_max_delay(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 10,
            initialDelayMs: 100,
            maxDelayMs: 500,
            backoffMultiplier: 3.0,
            jitter: false,
        );

        self::assertSame(500, $policy->delayForAttempt(5));
    }

    public function test_jitter_adds_up_to_25_percent(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 3,
            initialDelayMs: 100,
            maxDelayMs: 1000,
            backoffMultiplier: 1.0,
            jitter: true,
        );

        $delay = $policy->delayForAttempt(1);

        self::assertGreaterThanOrEqual(100, $delay);
        self::assertLessThanOrEqual(125, $delay);
    }
}
