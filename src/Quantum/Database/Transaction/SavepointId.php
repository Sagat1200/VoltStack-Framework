<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

/**
 * Value object representing a physical savepoint within an active transaction.
 *
 * Savepoint names are generated internally and are never derived from user
 * input. The format matches the existing convention used by TransactionManager.
 */
final readonly class SavepointId
{
    public function __construct(
        public string $value,
    ) {
    }

    /**
     * Generate a deterministic savepoint name for the given transaction id and
     * nesting depth.
     */
    public static function generate(string $transactionId, int $depth): self
    {
        return new self(sprintf(
            'quantum_sp_%s_%d',
            substr($transactionId, 0, 12),
            $depth,
        ));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
