<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

interface DialectInterface
{
    public function id(): string;

    public function quoteIdentifier(string $identifier): string;

    public function parameterPlaceholder(int $index): string;
}
