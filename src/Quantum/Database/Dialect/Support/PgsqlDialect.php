<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

/**
 * PostgresDialect: DoubleQuote + $N positional. Supports RETURNING.
 */
final class PgsqlDialect extends AbstractDialect
{
    public function __construct()
    {
        parent::__construct(name: 'pgsql', quoteChar: '"', paramStyle: 'positional_$n');
    }
    protected function supportsReturning(): bool { return true; }
}
