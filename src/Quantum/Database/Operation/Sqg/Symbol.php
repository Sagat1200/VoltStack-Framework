<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * Entrada de símbolo en SymbolTable.
 */
final readonly class Symbol
{
    public function __construct(
        public string $name,
        public string $kind,           // 'table', 'column', 'alias', 'parameter', 'cte'
        public ?string $scopeId = null,
        public ?DataType $type = DataType::Unknown,
        public ?string $tableAlias = null,
        public ?string $physicalColumn = null,
        public mixed $payload = null,  // Node reference
    ) {}
}