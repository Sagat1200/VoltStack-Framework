<?php

declare(strict_types=1);

namespace Quantum\Database\Driver;

use Quantum\Database\Contracts\DriverInterface;
use RuntimeException;

final class DriverRegistry
{
    /**
     * @var array<string, DriverInterface>
     */
    private array $drivers = [];

    public function register(string $id, DriverInterface $driver): void
    {
        $this->drivers[strtolower(trim($id))] = $driver;
    }

    public function has(string $id): bool
    {
        return isset($this->drivers[strtolower(trim($id))]);
    }

    public function resolve(string $id): DriverInterface
    {
        $normalized = strtolower(trim($id));

        if (! isset($this->drivers[$normalized])) {
            throw new RuntimeException(sprintf('Database driver [%s] is not registered.', $id));
        }

        return $this->drivers[$normalized];
    }
}
