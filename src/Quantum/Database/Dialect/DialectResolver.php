<?php

declare(strict_types=1);

namespace Quantum\Database\Dialect;

use Quantum\Database\Connection\ConnectionDefinition;
use Quantum\Database\Contracts\DialectInterface;

final class DialectResolver
{
    public function resolve(ConnectionDefinition $definition): DialectInterface
    {
        return match ($definition->dialectId()) {
            'sqlite' => new SqliteDialect(),
            default => new GenericDialect($definition->dialectId()),
        };
    }
}
