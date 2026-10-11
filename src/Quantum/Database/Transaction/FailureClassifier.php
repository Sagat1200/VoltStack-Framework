<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use PDOException;
use Quantum\Database\Transaction\Exception\TransactionCommitException;
use Quantum\Database\Transaction\Exception\TransactionNotActiveException;
use Quantum\Database\Transaction\Exception\TransactionOutcomeUnknownException;
use Quantum\Database\Transaction\Exception\TransactionRollbackException;
use Throwable;

/**
 * Classifies whether a failure is safe to retry at the transaction boundary.
 *
 * Retry eligibility is conservative: only failures that are known to leave the
 * transaction in a clean, rolled-back state are retryable. In particular,
 * TransactionOutcomeUnknownException is NEVER retryable because the database
 * may have committed.
 */
final class FailureClassifier
{
    /**
     * SQLSTATE codes that indicate a transient, retryable condition.
     */
    private const RETRYABLE_SQLSTATES = [
        '40001', // serialization failure
        '40P01', // deadlock detected
        '55P03', // lock not available
        '08003', // connection does not exist
        '08006', // connection failure
    ];

    public function isRetryable(Throwable $throwable): bool
    {
        // Unknown outcome is never retryable: the database may have committed.
        if ($throwable instanceof TransactionOutcomeUnknownException) {
            return false;
        }

        // A transaction that was never active cannot be retried as a unit.
        if ($throwable instanceof TransactionNotActiveException) {
            return false;
        }

        // Rollback failures are safe to retry: the previous unit was rolled
        // back (or never committed).
        if ($throwable instanceof TransactionRollbackException) {
            return true;
        }

        // Commit failures are retryable only when the outcome is known to not
        // be Unknown. The caller is responsible for attaching context; here we
        // treat a generic commit failure as retryable because the manager
        // converts Unknown-outcome failures into TransactionOutcomeUnknownException.
        if ($throwable instanceof TransactionCommitException) {
            return true;
        }

        if ($throwable instanceof PDOException) {
            return $this->isRetryablePdoException($throwable);
        }

        return false;
    }

    private function isRetryablePdoException(PDOException $exception): bool
    {
        $sqlState = $exception->getCode();

        if (! is_string($sqlState)) {
            // Fall back to message heuristics for drivers that do not expose
            // SQLSTATE cleanly.
            return $this->messageSuggestsTransientFailure($exception->getMessage());
        }

        if (in_array($sqlState, self::RETRYABLE_SQLSTATES, true)) {
            return true;
        }

        // Some drivers report HY000 (general error) for deadlocks and lock
        // timeouts; inspect the message in that case.
        if ($sqlState === 'HY000') {
            return $this->messageSuggestsTransientFailure($exception->getMessage());
        }

        return false;
    }

    private function messageSuggestsTransientFailure(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'deadlock')
            || str_contains($normalized, 'lock wait timeout')
            || str_contains($normalized, 'serialization failure')
            || str_contains($normalized, 'could not serialize access');
    }
}
