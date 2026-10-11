<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use Closure;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Throwable;

/**
 * Orchestrates transactional retry from a safe boundary.
 *
 * The whole callback (the entire transactional unit) is retried, never
 * individual statements. Between attempts the previous transaction is
 * guaranteed to have been rolled back by TransactionManager::transaction().
 *
 * The manager is resolved lazily via a factory closure to break the circular
 * dependency with TransactionManager (which needs the coordinator to delegate
 * retry-aware transaction() calls).
 */
final class TransactionRetryCoordinator
{
    /**
     * @param Closure(): TransactionManagerInterface $managerFactory
     */
    public function __construct(
        private readonly Closure $managerFactory,
        private readonly FailureClassifier $classifier,
    ) {
    }

    /**
     * Execute $callback inside a transaction, retrying the whole unit on
     * retryable failures according to $policy.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws Throwable when a non-retryable failure occurs or attempts are
     *                   exhausted; the last thrown exception is re-raised.
     */
    public function execute(callable $callback, RetryPolicy $policy, ?string $connectionName = null, ?TransactionDefinition $definition = null): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $policy->maxAttempts) {
            $attempt++;

            try {
                return ($this->managerFactory)()->transaction($callback, $connectionName, $definition);
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->classifier->isRetryable($exception)) {
                    throw $exception;
                }

                if ($attempt >= $policy->maxAttempts) {
                    break;
                }

                $delay = $policy->delayForAttempt($attempt);

                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
        }

        // Attempts exhausted.
        throw $lastException;
    }
}
