<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use PDO;
use Quantum\Database\Connection\ConnectionDefinition;

interface ConnectionInterface
{
    public function name(): string;

    public function definition(): ConnectionDefinition;

    public function driver(): DriverInterface;

    public function platform(): PlatformInterface;

    public function dialect(): DialectInterface;

    public function native(): NativeConnectionInterface;

    public function pdo(): PDO;

    public function isConnected(): bool;

    public function disconnect(): void;
}
