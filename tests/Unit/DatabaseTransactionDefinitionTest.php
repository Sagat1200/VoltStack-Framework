<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\TransactionDefinition;
use Quantum\Database\Transaction\TransactionIsolation;
use Quantum\Database\Transaction\TransactionPropagation;
use Quantum\Database\Transaction\RetryPolicy;

final class DatabaseTransactionDefinitionTest extends TestCase
{
    public function test_defaults_match_expected_values(): void
    {
        $definition = TransactionDefinition::defaults();

        self::assertSame(TransactionIsolation::RepeatableRead, $definition->isolation);
        self::assertSame(TransactionPropagation::Required, $definition->propagation);
        self::assertFalse($definition->readOnly);
        self::assertNull($definition->timeout);
        self::assertNull($definition->retryPolicy);
    }

    public function test_isolation_enum_backed_values(): void
    {
        self::assertSame('READ UNCOMMITTED', TransactionIsolation::ReadUncommitted->value);
        self::assertSame('READ COMMITTED', TransactionIsolation::ReadCommitted->value);
        self::assertSame('REPEATABLE READ', TransactionIsolation::RepeatableRead->value);
        self::assertSame('SERIALIZABLE', TransactionIsolation::Serializable->value);
    }

    public function test_with_isolation_returns_new_instance(): void
    {
        $definition = TransactionDefinition::defaults();
        $serializable = $definition->withIsolation(TransactionIsolation::Serializable);

        self::assertNotSame($definition, $serializable);
        self::assertSame(TransactionIsolation::RepeatableRead, $definition->isolation);
        self::assertSame(TransactionIsolation::Serializable, $serializable->isolation);
    }

    public function test_with_propagation_returns_new_instance(): void
    {
        $definition = TransactionDefinition::defaults();
        $mandatory = $definition->withPropagation(TransactionPropagation::Mandatory);

        self::assertNotSame($definition, $mandatory);
        self::assertSame(TransactionPropagation::Mandatory, $mandatory->propagation);
    }

    public function test_with_read_only(): void
    {
        $definition = TransactionDefinition::defaults();
        $readOnly = $definition->withReadOnly(true);

        self::assertFalse($definition->readOnly);
        self::assertTrue($readOnly->readOnly);
    }

    public function test_with_timeout(): void
    {
        $definition = TransactionDefinition::defaults();
        $withTimeout = $definition->withTimeout(30);

        self::assertNull($definition->timeout);
        self::assertSame(30, $withTimeout->timeout);
    }

    public function test_with_retry_policy(): void
    {
        $definition = TransactionDefinition::defaults();
        $policy = RetryPolicy::defaults();
        $withRetry = $definition->withRetryPolicy($policy);

        self::assertNull($definition->retryPolicy);
        self::assertSame($policy, $withRetry->retryPolicy);
    }

    public function test_without_retry_policy_strips_policy(): void
    {
        $policy = RetryPolicy::defaults();
        $definition = TransactionDefinition::defaults()->withRetryPolicy($policy);

        self::assertNotNull($definition->retryPolicy);

        $stripped = $definition->withoutRetryPolicy();

        self::assertNull($stripped->retryPolicy);
    }
}
