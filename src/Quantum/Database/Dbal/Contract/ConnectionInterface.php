<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Contract;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;

/**
 * Conexión lógica autoritativa. No thread-safe; 1 por worker.
 */
interface ConnectionInterface
{
    /** @throws DbalException kind=Configuration|Connectivity */
    public function connect(): void;

    public function isConnected(): bool;

    public function close(): void;

    /**
     * Ping round-trip; si falla marca la conexión cerrada y devuelve false.
     */
    public function ping(): bool;

    /** @throws DbalException kind=Validation|Configuration */
    public function prepare(string $sql): StatementInterface;

    /**
     * Execute no-SELECT (DDL/INSERT/UPDATE/DELETE).
     *
     * @param list<mixed> $params bindings posicionales
     * @throws DbalException
     */
    public function executeStatement(string $sql, array $params = []): QueryResult;

    /**
     * Execute SELECT (devuelve QueryResult iterable).
     *
     * @param list<mixed> $params
     * @throws DbalException
     */
    public function executeQuery(string $sql, array $params = []): QueryResult;

    public function lastInsertId(?string $sequenceName = null): string|int|null;

    /**
     * Quotea identifier (table, column, schema). 1, 2, 3 partes aceptadas.
     * @throws DbalException kind=Validation (identifier vacío)
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Quoteo seguro de valor (SOLO usar si Param binding no es posible).
     */
    public function quoteString(string $value): string;

    // ---------- Transacciones ----------

    public function inTransaction(): bool;

    /** @throws DbalException kind=Internal|Concurrency */
    public function beginTransaction(): bool;

    /** @throws DbalException */
    public function commit(): bool;

    /** @throws DbalException */
    public function rollback(): bool;

    /** @throws DbalException kind=Capability|Concurrency */
    public function createSavepoint(string $identifier): void;
    public function releaseSavepoint(string $identifier): void;
    public function rollbackToSavepoint(string $identifier): void;

    public function setTransactionIsolation(TransactionIsolation $level): void;

    // ---------- Meta ----------

    public function getDriverInfo(): DriverInfo;

    public function getCapabilities(): DatabaseCapabilitySet;

    /**
     * @return float hrtime-based timestamp (seconds with fraction) of last operation execute.
     * @internal para idle checks.
     */
    public function lastUsedAtSeconds(): float;

    /**
     * Driver handle nativo. @internal.
     */
    public function getNativeHandle(): mixed;
}
