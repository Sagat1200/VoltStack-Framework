<?php

declare(strict_types=1);

namespace Quantum\Database\Factories;

use Quantum\Database\Contracts\FactoryInterface;
use RuntimeException;
use VoltStack\Framework\Application;

/**
 * Discovers factory PHP files inside the configured directory.
 *
 * Mirrors `MigrationDiscovery` semantics: each `*.php` file inside the
 * factories directory (defaults to `database/factories`) is `require`d and
 * its return value is used to build the `DiscoveredFactory` value-object.
 *
 * Accepted return shapes from a single factory file are:
 *   - an anonymous object already implementing `FactoryInterface` (typical),
 *   - a class-string FQCN whose instances implement `FactoryInterface`.
 *
 * Both are instantiated/resolved through the application container so the
 * constructor argument `Application $application` (used by AbstractFactory)
 * is injected automatically.
 */
final class FactoryDiscovery
{
    public function __construct(
        private readonly Application $application,
    ) {
    }

    /**
     * @return list<DiscoveredFactory>
     */
    public function discover(?string $path = null): array
    {
        $targetPath = $path ?? $this->defaultPath();

        if (! is_dir($targetPath)) {
            return [];
        }

        $files = glob(rtrim($targetPath, '\\/') . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $factories = [];

        foreach ($files as $file) {
            $loaded = require $file;

            if (is_callable($loaded)) {
                $loaded = $loaded($this->application);
            }

            if ($loaded instanceof FactoryInterface) {
                $factories[] = new DiscoveredFactory(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: $loaded,
                );

                continue;
            }

            if (is_string($loaded) && is_subclass_of($loaded, FactoryInterface::class)) {
                $factories[] = new DiscoveredFactory(
                    name: pathinfo($file, PATHINFO_FILENAME),
                    path: $file,
                    instance: $this->application->make($loaded),
                );

                continue;
            }

            throw new RuntimeException(sprintf(
                'Factory file [%s] must return a FactoryInterface instance, a class-string thereof, or a callable(Application): FactoryInterface factory.',
                $file,
            ));
        }

        return $factories;
    }

    public function defaultPath(): string
    {
        return $this->application->basePath('database/factories');
    }
}
