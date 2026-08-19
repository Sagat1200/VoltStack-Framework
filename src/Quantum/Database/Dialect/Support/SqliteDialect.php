<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

/** SQLite: DoubleQuote + ? positional. RETURNING support 3.35+. Enable por defecto. */
final class SqliteDialect extends AbstractDialect
{
    public function __construct()
    {
        parent::__construct(name: 'sqlite', quoteChar: '"', paramStyle: 'positional_q');
    }
    protected function supportsReturning(): bool { return true; }
}
