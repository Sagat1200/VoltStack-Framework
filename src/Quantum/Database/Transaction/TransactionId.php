<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

final readonly class TransactionId
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(12)));
    }
}
