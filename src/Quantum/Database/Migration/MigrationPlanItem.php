<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationPlanItem
{
    public function __construct(
        public int $position,
        public MigrationInterface $migration,
    ) {}

    public function version(): string
    {
        return $this->migration->version();
    }

    public function migrationClass(): string
    {
        return $this->migration::class;
    }

    public function description(): string
    {
        return $this->migration->description();
    }

    public function isTransactional(): bool
    {
        return $this->migration->isTransactional();
    }

    /**
     * @return array{
     *   position:int,
     *   version:string,
     *   migration:string,
     *   description:string,
     *   transactional:bool
     * }
     */
    public function fingerprintPayload(): array
    {
        return [
            'position' => $this->position,
            'version' => $this->version(),
            'migration' => $this->migrationClass(),
            'description' => $this->description(),
            'transactional' => $this->isTransactional(),
        ];
    }
}