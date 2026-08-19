<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Value;

use Quantum\Database\Dbal\Enum\FetchMode;

/**
 * Resultado portátil de una query. Implementa IteratorAggregate.
 * Libera cursores en __destruct.
 */
final class QueryResult implements \IteratorAggregate, \Countable
{
    /** @var list<ColumnMeta> */
    private readonly array $columnMeta;
    private readonly bool $isSelect;
    private readonly int $affectedRows;
    private readonly int $columnCount;

    /**
     * @param \Closure(): \Generator<array<string,mixed>> $rowGenerator
     * @param \Closure(): void $cleanup
     */
    public function __construct(
        bool $isSelect,
        int $affectedRows,
        array $columnMeta,
        private readonly \Closure $rowGenerator,
        private readonly \Closure $cleanup,
        ?int $columnCount = null,
    ) {
        $this->isSelect = $isSelect;
        $this->affectedRows = $affectedRows;
        $this->columnMeta = $columnMeta;
        $this->columnCount = $columnCount ?? count($columnMeta);
    }

    public function isSelect(): bool { return $this->isSelect; }
    public function affectedRows(): int { return $this->affectedRows; }
    /** @return list<ColumnMeta> */
    public function columnMeta(): array { return $this->columnMeta; }
    public function columnCount(): int { return $this->columnCount; }

    public function getIterator(): \Generator
    {
        if (!$this->isSelect) { return; yield from []; }
        $gen = ($this->rowGenerator)();
        yield from $gen;
    }

    public function count(): int
    {
        return $this->isSelect ? count($this->fetchAllAssoc()) : 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchAllAssoc(): array
    {
        if (!$this->isSelect) return [];
        return iterator_to_array($this->getIterator(), preserve_keys: false);
    }

    /**
     * @return list<mixed> values de 1 columna
     */
    public function fetchColumn(int $column = 0): array
    {
        $out = [];
        foreach ($this as $row) {
            $vals = array_values($row);
            $out[] = $vals[$column] ?? null;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetchOneAssoc(): ?array
    {
        foreach ($this as $row) {
            return $row;
        }
        return null;
    }

    public function __destruct()
    {
        ($this->cleanup)();
    }
}
