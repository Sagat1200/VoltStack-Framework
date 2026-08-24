<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseHealthSnapshot
{
    /**
     * @param list<DatabaseCircuitStateSnapshot> $segments
     */
    public function __construct(
        public int $totalSegments,
        public int $closedSegments,
        public int $halfOpenSegments,
        public int $openSegments,
        public array $segments,
    ) {
    }

    /**
     * @return array{
     *   total_segments:int,
     *   closed_segments:int,
     *   half_open_segments:int,
     *   open_segments:int,
     *   segments:list<array<string, scalar|null>>
     * }
     */
    public function toArray(): array
    {
        return [
            'total_segments' => $this->totalSegments,
            'closed_segments' => $this->closedSegments,
            'half_open_segments' => $this->halfOpenSegments,
            'open_segments' => $this->openSegments,
            'segments' => array_map(
                static fn(DatabaseCircuitStateSnapshot $segment): array => $segment->toArray(),
                $this->segments,
            ),
        ];
    }
}
