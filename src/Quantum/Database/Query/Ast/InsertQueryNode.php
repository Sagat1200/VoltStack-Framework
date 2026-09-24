<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Ast;

use Quantum\Database\Query\QueryMetadata;

final readonly class InsertQueryNode
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public TableNode $into,
        public array $values,
        public QueryMetadata $metadata,
    ) {
    }
}
