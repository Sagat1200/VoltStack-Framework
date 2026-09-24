<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

use PDO;
use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\DialectInterface;
use Quantum\Database\Contracts\DriverInterface;
use Quantum\Database\Contracts\NativeConnectionInterface;
use Quantum\Database\Contracts\PlatformInterface;

final class Connection implements ConnectionInterface
{
    private ?NativeConnectionInterface $native = null;

    public function __construct(
        private readonly ConnectionDefinition $definition,
        private readonly DriverInterface $driverInstance,
        private readonly PlatformInterface $platformInstance,
        private readonly DialectInterface $dialectInstance,
    ) {
    }

    public function name(): string
    {
        return $this->definition->name;
    }

    public function definition(): ConnectionDefinition
    {
        return $this->definition;
    }

    public function driver(): DriverInterface
    {
        return $this->driverInstance;
    }

    public function platform(): PlatformInterface
    {
        return $this->platformInstance;
    }

    public function dialect(): DialectInterface
    {
        return $this->dialectInstance;
    }

    public function native(): NativeConnectionInterface
    {
        if ($this->native === null || ! $this->native->isConnected()) {
            $this->native = $this->driverInstance->connect($this->definition);
        }

        return $this->native;
    }

    public function pdo(): PDO
    {
        /** @var PDO $pdo */
        $pdo = $this->native()->raw();

        return $pdo;
    }

    public function isConnected(): bool
    {
        return $this->native !== null && $this->native->isConnected();
    }

    public function disconnect(): void
    {
        if ($this->native === null) {
            return;
        }

        $this->native->disconnect();
        $this->native = null;
    }
}
