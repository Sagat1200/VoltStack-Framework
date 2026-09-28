<?php

declare(strict_types=1);

namespace Quantum\Database\Seeders;

use Quantum\Database\Contracts\SeederInterface;
use RuntimeException;
use VoltStack\Framework\Application;

/**
 * Discovers seeder PHP files inside the configured directory.
 *
 * Mirrors `MigrationDiscovery` / `FactoryDiscovery` semantics: each
 * `*.php` file inside `database/seeders` (or an explicit path) is
 * `require`d and its return value converted to a `DiscoveredSeeder` VO.
 *
 * Accepted return shapes per seeder file:
 *   - an anonymous object already implementing `SeederInterface`,
 *   - a class-string whose instances implement `SeederInterface`.
 */
final class SeederDiscovery
{
    public function __construct(
        private readonly Application $application,
    ) {
    }

    /**
     * @return list<DiscoveredSeeder>
     */
    public function discover(?string $path = null): array
    {
        $targetPath = $path ?? $this->defaultPath();

        if (! is_dir($targetPath)) {
            return [];
        }

        $files = glob(rtrim($targetPath, '\\/') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $seeders = [];

        foreach ($files as $file) {
            $loaded = require $file;

            if (is_callable($loaded)) {
                $loaded = $loaded($this->application);
            }

            if ($loaded instanceof SeederInterface) {
                $seeders[] = new DiscoveredSeeder(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: $loaded,
                );

                continue;
            }

            if (is_string($loaded) && is_subclass_of($loaded, SeederInterface::class)) {
                $seeders[] = new DiscoveredSeeder(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: $this->application->make($loaded),
                );

                continue;
            }

            throw new RuntimeException(sprintf(
                'Seeder file [%s] must return a SeederInterface instance, a class-string thereof, or a callable(Application): SeederInterface seeder.',
                $file,
            ));
        }

        return $seeders;
    }

    public function defaultPath(): string
    {
        return $this->application->basePath('database/seeders');
    }
}
