<?php

declare(strict_types=1);

namespace Quantum\Database\Driver;

use PDO;
use Quantum\Database\Contracts\NativeConnectionInterface;
use RuntimeException;

final class PdoNativeConnection implements NativeConnectionInterface
{
    public function __construct(
        private ?PDO $pdo,
    ) {
    }

    public function raw(): object
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The PDO native connection has already been disconnected.');
        }

        return $this->pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The PDO native connection has already been disconnected.');
        }

        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }
}
