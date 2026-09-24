<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use RuntimeException;
use VoltStack\Framework\Application;

final class MigrationDiscovery
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    /**
     * @return list<DiscoveredMigration>
     */
    public function discover(?string $path = null): array
    {
        $targetPath = $path ?? $this->defaultPath();

        if (! is_dir($targetPath)) {
            return [];
        }

        $files = glob(rtrim($targetPath, '\\/') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $migrations = [];

        foreach ($files as $file) {
            $loaded = require $file;

            if ($loaded instanceof MigrationInterface) {
                $migrations[] = new DiscoveredMigration(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: $loaded,
                );

                continue;
            }

            if (is_string($loaded) && is_subclass_of($loaded, MigrationInterface::class)) {
                $migrations[] = new DiscoveredMigration(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: new $loaded(),
                );

                continue;
            }

            throw new RuntimeException(sprintf('Migration file [%s] must return a MigrationInterface instance or class-string.', $file));
        }

        return $migrations;
    }

    public function defaultPath(): string
    {
        return $this->app->basePath('database/migrations');
    }
}
