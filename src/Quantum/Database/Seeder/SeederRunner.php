<?php

declare(strict_types=1);

namespace Quantum\Database\Seeder;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Factory\FactoryManager;
use VoltStack\Framework\Application;

final class SeederRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly ConnectionInterface $connection,
        private readonly SeederDiscovery $discovery,
        private readonly FactoryManager $factories,
    ) {}

    /**
     * @return list<array{name:string,class:string,description:string}>
     */
    public function status(): array
    {
        $rows = [];

        foreach ($this->discovery->discover() as $seeder) {
            $rows[] = [
                'name' => $seeder->name(),
                'class' => $seeder::class,
                'description' => $seeder->description(),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $targets
     * @return array{planned:int,seeded:list<string>,pretended:list<string>}
     */
    public function seed(array $targets = [], bool $pretend = false, bool $force = false): array
    {
        $selected = $this->selectSeeders($targets);

        if ($selected === []) {
            return [
                'planned' => 0,
                'seeded' => [],
                'pretended' => [],
            ];
        }

        if ($this->app->isProduction() && !$force && (bool) $this->app->config('database.seeders.require_force_in_production', true)) {
            throw new \RuntimeException('db:seed requiere --force en producción.');
        }

        if ($pretend) {
            return [
                'planned' => count($selected),
                'seeded' => [],
                'pretended' => array_map(static fn(SeederInterface $seeder): string => $seeder->name(), $selected),
            ];
        }

        $references = new SeedReferenceStore();
        $context = new SeedExecutionContext($this->app, $this->connection, $references, $this->factories, false);
        $seeded = [];

        foreach ($selected as $seeder) {
            $this->runSeeder($seeder, $context);
            $seeded[] = $seeder->name();
        }

        return [
            'planned' => count($selected),
            'seeded' => $seeded,
            'pretended' => [],
        ];
    }

    /**
     * @param list<string> $targets
     * @return list<SeederInterface>
     */
    private function selectSeeders(array $targets): array
    {
        $discovered = $this->discovery->discover();
        if ($targets === []) {
            return $discovered;
        }

        $byName = [];
        $byClass = [];

        foreach ($discovered as $seeder) {
            $byName[$seeder->name()] = $seeder;
            $byClass[$seeder::class] = $seeder;
        }

        $selected = [];

        foreach ($targets as $target) {
            $normalized = trim($target);
            if ($normalized === '') {
                continue;
            }

            $seeder = $byName[$normalized] ?? $byClass[$normalized] ?? null;
            if (!$seeder instanceof SeederInterface) {
                throw new \RuntimeException(sprintf('Seeder [%s] no fue encontrado.', $normalized));
            }

            $selected[$seeder->name()] = $seeder;
        }

        return array_values($selected);
    }

    private function runSeeder(SeederInterface $seeder, SeedExecutionContext $context): void
    {
        $useTransaction = $seeder->isTransactional() && !$this->connection->inTransaction();

        if (!$useTransaction) {
            $seeder->run($context);
            return;
        }

        $this->connection->beginTransaction();

        try {
            $seeder->run($context);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollback();
            }

            throw $e;
        }
    }
}
