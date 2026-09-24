<?php

declare(strict_types=1);

namespace Quantum\Database\Dialect;

use Quantum\Database\Contracts\DialectInterface;

final class GenericDialect implements DialectInterface
{
    public function __construct(
        private readonly string $idValue,
    ) {
    }

    public function id(): string
    {
        return $this->idValue;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function parameterPlaceholder(int $index): string
    {
        return '?';
    }
}
