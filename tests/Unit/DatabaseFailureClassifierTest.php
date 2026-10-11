<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Transaction\FailureClassifier;
use Quantum\Database\Transaction\Exception\TransactionCommitException;
use Quantum\Database\Transaction\Exception\TransactionNotActiveException;
use Quantum\Database\Transaction\Exception\TransactionOutcomeUnknownException;
use Quantum\Database\Transaction\Exception\TransactionRollbackException;
use PDOException;
use ReflectionClass;
use RuntimeException;

final class DatabaseFailureClassifierTest extends TestCase
{
    private FailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new FailureClassifier();
    }

    /**
     * Build a PDOException with a specific SQLSTATE code set on the protected
     * $code property (PDOException stores the SQLSTATE there at runtime).
     */
    private function pdoException(string $message, string $sqlState): PDOException
    {
        $exception = new PDOException($message);
        $reflection = new ReflectionClass($exception);
        $codeProperty = $reflection->getProperty('code');
        $codeProperty->setAccessible(true);
        $codeProperty->setValue($exception, $sqlState);

        return $exception;
    }

    public function test_transaction_rollback_exception_is_retryable(): void
    {
        self::assertTrue($this->classifier->isRetryable(new TransactionRollbackException('rolled back')));
    }

    public function test_transaction_commit_exception_is_retryable(): void
    {
        self::assertTrue($this->classifier->isRetryable(new TransactionCommitException('commit failed')));
    }

    public function test_outcome_unknown_exception_is_not_retryable(): void
    {
        self::assertFalse($this->classifier->isRetryable(new TransactionOutcomeUnknownException('unknown')));
    }

    public function test_not_active_exception_is_not_retryable(): void
    {
        self::assertFalse($this->classifier->isRetryable(new TransactionNotActiveException('not active')));
    }

    public function test_pdo_exception_with_retryable_sqlstate_is_retryable(): void
    {
        $exception = $this->pdoException('deadlock detected', '40P01');

        self::assertTrue($this->classifier->isRetryable($exception));
    }

    public function test_pdo_exception_with_other_sqlstate_is_not_retryable(): void
    {
        $exception = $this->pdoException('syntax error', '42000');

        self::assertFalse($this->classifier->isRetryable($exception));
    }

    public function test_pdo_hy000_with_deadlock_message_is_retryable(): void
    {
        $exception = $this->pdoException('Deadlock found when trying to get lock', 'HY000');

        self::assertTrue($this->classifier->isRetryable($exception));
    }

    public function test_pdo_hy000_with_lock_wait_timeout_message_is_retryable(): void
    {
        $exception = $this->pdoException('Lock wait timeout exceeded', 'HY000');

        self::assertTrue($this->classifier->isRetryable($exception));
    }

    public function test_pdo_hy000_without_transient_message_is_not_retryable(): void
    {
        $exception = $this->pdoException('no such table', 'HY000');

        self::assertFalse($this->classifier->isRetryable($exception));
    }

    public function test_arbitrary_exception_is_not_retryable(): void
    {
        self::assertFalse($this->classifier->isRetryable(new RuntimeException('generic')));
    }
}
