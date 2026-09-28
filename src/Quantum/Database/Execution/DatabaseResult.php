<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

/**
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final readonly class DatabaseResult implements Countable, IteratorAggregate
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public DatabaseResultType $type,
        public array $rows = [],
        public int $affectedRows = 0,
        public int|string|null $lastInsertId = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    public function scalar(): mixed
    {
        $row = $this->first();

        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->affectedRows === 0;
    }

    public function count(): int
    {
        if ($this->type === DatabaseResultType::Rows) {
            return count($this->rows);
        }

        return $this->affectedRows;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}
