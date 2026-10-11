<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Immutable value object describing the desired characteristics of a
 * transaction before it begins.
 *
 * Passed to TransactionManager::begin() so isolation, propagation, read-only
 * mode, timeout and retry policy are declared explicitly rather than inferred.
 */
final readonly class TransactionDefinition
{
    public function __construct(
        public TransactionIsolation $isolation = TransactionIsolation::RepeatableRead,
        public TransactionPropagation $propagation = TransactionPropagation::Required,
        public bool $readOnly = false,
        public ?int $timeout = null,
        public ?RetryPolicy $retryPolicy = null,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function withIsolation(TransactionIsolation $isolation): self
    {
        return new self($isolation, $this->propagation, $this->readOnly, $this->timeout, $this->retryPolicy);
    }

    public function withPropagation(TransactionPropagation $propagation): self
    {
        return new self($this->isolation, $propagation, $this->readOnly, $this->timeout, $this->retryPolicy);
    }

    public function withReadOnly(bool $readOnly): self
    {
        return new self($this->isolation, $this->propagation, $readOnly, $this->timeout, $this->retryPolicy);
    }

    public function withTimeout(?int $timeout): self
    {
        return new self($this->isolation, $this->propagation, $this->readOnly, $timeout, $this->retryPolicy);
    }

    public function withRetryPolicy(?RetryPolicy $retryPolicy): self
    {
        return new self($this->isolation, $this->propagation, $this->readOnly, $this->timeout, $retryPolicy);
    }

    public function withoutRetryPolicy(): self
    {
        return new self($this->isolation, $this->propagation, $this->readOnly, $this->timeout, null);
    }
}
