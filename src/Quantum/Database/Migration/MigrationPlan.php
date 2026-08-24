<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Value\DriverInfo;

final readonly class MigrationPlan
{
    /**
     * @param list<MigrationPlanItem> $items
     */
    public function __construct(
        public string $operation,
        public DriverInfo $driver,
        public DatabaseCapabilitySet $capabilities,
        public string $repositoryTable,
        public ?int $batchNumber,
        public ?int $stepLimit,
        public int $discoveredCount,
        public int $appliedCount,
        public array $items,
        public string $fingerprint,
    ) {
    }

    /**
     * @param list<MigrationInterface> $migrations
     */
    public static function forMigrate(
        DriverInfo $driver,
        DatabaseCapabilitySet $capabilities,
        string $repositoryTable,
        ?int $batchNumber,
        ?int $stepLimit,
        int $discoveredCount,
        int $appliedCount,
        array $migrations,
    ): self {
        $items = [];

        foreach (array_values($migrations) as $index => $migration) {
            $items[] = new MigrationPlanItem(
                position: $index + 1,
                migration: $migration,
            );
        }

        $payload = [
            'format' => 'voltstack-migration-plan-v1',
            'operation' => 'migrate',
            'repository_table' => $repositoryTable,
            'batch_number' => $batchNumber,
            'step_limit' => $stepLimit,
            'discovered_count' => $discoveredCount,
            'applied_count' => $appliedCount,
            'driver' => [
                'name' => $driver->driverName,
                'server_version' => $driver->serverVersion,
                'database' => $driver->databaseName,
                'charset' => $driver->charset,
            ],
            'capabilities' => self::capabilitiesPayload($capabilities),
            'items' => array_map(
                static fn(MigrationPlanItem $item): array => $item->fingerprintPayload(),
                $items,
            ),
        ];

        return new self(
            operation: 'migrate',
            driver: $driver,
            capabilities: $capabilities,
            repositoryTable: $repositoryTable,
            batchNumber: $batchNumber,
            stepLimit: $stepLimit,
            discoveredCount: $discoveredCount,
            appliedCount: $appliedCount,
            items: $items,
            fingerprint: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function plannedCount(): int
    {
        return count($this->items);
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    public function transactionalCount(): int
    {
        return count(array_filter(
            $this->items,
            static fn(MigrationPlanItem $item): bool => $item->isTransactional(),
        ));
    }

    public function nonTransactionalCount(): int
    {
        return $this->plannedCount() - $this->transactionalCount();
    }

    /**
     * @return list<string>
     */
    public function versions(): array
    {
        return array_map(
            static fn(MigrationPlanItem $item): string => $item->version(),
            $this->items,
        );
    }

    /**
     * @return array<string, scalar|array<array-key, scalar>>
     */
    private static function capabilitiesPayload(DatabaseCapabilitySet $capabilities): array
    {
        $payload = [
            'returningClause' => $capabilities->returningClause,
            'upsertOnConflict' => $capabilities->upsertOnConflict,
            'upsertOnDuplicateKey' => $capabilities->upsertOnDuplicateKey,
            'savepoints' => $capabilities->savepoints,
            'cteRecursive' => $capabilities->cteRecursive,
            'windowFunctions' => $capabilities->windowFunctions,
            'jsonb' => $capabilities->jsonb,
            'arrayTypes' => $capabilities->arrayTypes,
            'uuidNative' => $capabilities->uuidNative,
            'temporalTemporal' => $capabilities->temporalTemporal,
            'multipleActiveResultSets' => $capabilities->multipleActiveResultSets,
            'batchedInserts' => $capabilities->batchedInserts,
            'triggersPerRow' => $capabilities->triggersPerRow,
            'quoteStyle' => $capabilities->quoteStyle,
            'paramStyle' => $capabilities->paramStyle,
            'extra' => $capabilities->extra,
        ];

        if ($payload['extra'] !== []) {
            ksort($payload['extra']);
        }

        return $payload;
    }
}
