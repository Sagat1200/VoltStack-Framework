<?php

declare(strict_types=1);

namespace Quantum\Database\Dialect;

use Quantum\Database\Contracts\DialectInterface;

final class SqliteDialect implements DialectInterface
{
    public function id(): string
    {
        return 'sqlite';
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
