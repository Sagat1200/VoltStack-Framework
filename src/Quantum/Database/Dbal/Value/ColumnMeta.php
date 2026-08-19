<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Value;

/**
 * Metadata de columna devuelta por SELECT / describe.
 */
final readonly class ColumnMeta
{
    public function __construct(
        public int $ordinal,         // 0-based
        public string $name,         // alias de columna tal como viene en SELECT
        public string $nativeType,   // varchar, int4, datetime…
        public int $maxLength = -1,  // -1 si N/A
        public bool $isNullable = true,
    ) {}
}
