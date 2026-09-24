<?php

declare(strict_types=1);

namespace Quantum\Database\Query\Model;

use Quantum\Database\Query\QueryInterface;
use Quantum\Database\Query\QueryMetadata;
use Quantum\Database\Query\QueryType;

final readonly class InsertQuery implements QueryInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public TableReference $into,
        public array $values,
        public QueryMetadata $queryMetadata = new QueryMetadata(),
    ) {
    }

    public function type(): QueryType
    {
        return QueryType::Insert;
    }

    public function metadata(): QueryMetadata
    {
        return $this->queryMetadata;
    }
}
