<?php

declare(strict_types=1);

namespace Quantum\Config\Loading;

use Quantum\Config\ConfigDocument;

final readonly class LoadedConfigCollection
{
    /**
     * @param array<string, mixed> $items
     * @param list<ConfigDocument> $documents
     * @param array<string, mixed> $provenance
     */
    public function __construct(
        private array $items,
        private array $documents,
        private array $provenance,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<ConfigDocument>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }
}
