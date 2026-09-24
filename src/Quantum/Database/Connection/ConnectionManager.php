<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use RuntimeException;

final class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    public function __construct(
        private readonly ConnectionDefinitionRegistry $definitions,
        private readonly ConnectionFactory $factory,
    ) {
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        $target = $name ?? $this->defaultConnectionName();

        if (isset($this->connections[$target])) {
            return $this->connections[$target];
        }

        $definition = $this->definitions->find($target);

        if ($definition === null) {
            throw new RuntimeException(sprintf('Database connection [%s] is not defined.', $target));
        }

        return $this->connections[$target] = $this->factory->create($definition);
    }

    public function has(string $name): bool
    {
        return $this->definitions->has($name);
    }

    public function defaultConnectionName(): string
    {
        return $this->definitions->defaultConnectionName();
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }
}
