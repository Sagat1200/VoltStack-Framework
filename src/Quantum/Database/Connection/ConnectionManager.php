<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use RuntimeException;
use Throwable;

final class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    /**
     * @var array<string, array{name: string, scope_id: ?string, runtime_request_id: ?string, opened_at: float}>
     */
    private array $leases = [];

    public function __construct(
        private readonly ConnectionDefinitionRegistry $definitions,
        private readonly ConnectionFactory $factory,
        private readonly ?DatabaseExecutionScope $scope = null,
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

        $this->leases[$target] = [
            'name' => $target,
            'scope_id' => $this->scope?->id(),
            'runtime_request_id' => $this->scope?->runtimeRequestId(),
            'opened_at' => microtime(true),
        ];

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
        $this->leases = [];
    }

    /**
     * @return list<array{name: string, scope_id: ?string, runtime_request_id: ?string, opened_at: float, connected: bool}>
     */
    public function snapshotLeases(): array
    {
        $leases = [];

        foreach ($this->leases as $name => $lease) {
            $leases[] = [
                ...$lease,
                'connected' => isset($this->connections[$name]) && $this->connections[$name]->isConnected(),
            ];
        }

        return $leases;
    }

    /**
     * @return array{disconnected: int, skipped: list<string>, errors: list<Throwable>}
     */
    public function disconnectAllOwned(?string $scopeId = null): array
    {
        $disconnected = 0;
        $skipped = [];
        $errors = [];

        foreach ($this->connections as $name => $connection) {
            $lease = $this->leases[$name] ?? null;

            if ($scopeId !== null && ($lease['scope_id'] ?? null) !== $scopeId) {
                $skipped[] = $name;

                continue;
            }

            try {
                $connection->disconnect();
                $disconnected++;
                unset($this->connections[$name], $this->leases[$name]);
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }
        }

        return [
            'disconnected' => $disconnected,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
