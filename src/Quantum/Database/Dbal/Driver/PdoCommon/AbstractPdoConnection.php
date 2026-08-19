<?php

declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\PdoCommon;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind as FK;
use Quantum\Database\Dbal\Enum\ParamType;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\ExceptionMapperInterface;

/**
 * Clase abstracta base para implementaciones PDO.
 * Define plantilla para crear \PDO handle (connectInternal) y ExceptionMapper.
 */
abstract class AbstractPdoConnection implements ConnectionInterface
{
    protected ?\PDO $pdo = null;
    private float $lastUsedSeconds = 0.0;
    private bool $autoCommitState = true;
    /** @var array<int,\PDOStatement> statements alive para forzar cierre en tx/savepoint control */
    private array $livePdoStatements = [];

    public function __construct(
        protected readonly array $config,
        protected readonly ExceptionMapperInterface $mapper,
        protected readonly string $driverName,
        protected readonly string $quoteCharacter, // '"' or '`'
        protected readonly bool $supportsSavepoints,
        protected readonly string $paramStyle,
    ) {}

    // ---------------- lifecycle ----------------

    final public function connect(): void
    {
        if ($this->pdo !== null) return;
        try {
            $this->pdo = $this->connectInternal($this->config);
        } catch (\Throwable $t) {
            $msg = DbalException::redactMessage($t->getMessage());
            $wrapper = new \RuntimeException($msg, (int)$t->getCode(), $t);
            throw DbalException::wrap(
                $wrapper,
                $this->isConnectivity($t) ? FK::Connectivity : FK::Configuration,
                'connect',
                null,
                $this->isRetryableConnect($t)
            );
        }
        $this->onAfterConnect($this->pdo);
        $this->touchLastUsed();
    }

    /** @return bool true si el error indica claramente un problema de red (2002, 2003, 08006, etc.) */
    abstract protected function isConnectivity(\Throwable $t): bool;
    abstract protected function isRetryableConnect(\Throwable $t): bool;

    /**
     * @param array<string,mixed> $config driver-specific
     * @throws \Throwable
     */
    abstract protected function connectInternal(array $config): \PDO;

    /**
     * Post-connect hook (set timezone, charset, isolation defaults, etc).
     */
    protected function onAfterConnect(\PDO $pdo): void
    {
        try {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            @$pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        } catch (\Throwable) { /* keep existing attr */
        }
    }

    final public function isConnected(): bool
    {
        return $this->pdo instanceof \PDO;
    }

    final public function close(): void
    {
        $this->pdo = null;
    }

    final public function ping(): bool
    {
        if ($this->pdo === null) return false;
        try {
            $select = $this->pingSelect();
            $stmt = $this->pdo->query($select);
            if ($stmt) {
                $stmt->closeCursor();
                $this->touchLastUsed();
                return true;
            }
            $this->pdo = null;
            return false;
        } catch (\Throwable) {
            $this->pdo = null;
            return false;
        }
    }

    protected function pingSelect(): string
    {
        return 'SELECT 1';
    }

    final public function lastUsedAtSeconds(): float
    {
        return $this->lastUsedSeconds;
    }
    public function touchLastUsed(): void
    {
        $this->lastUsedSeconds = hrtime(true) / 1_000_000_000;
    }

    // ---------------- statement tracking ----------------
    // SQLite (y otros drivers) no permiten ejecutar exec() si quedan statements pendientes.
    // Mantener weak map permite forzar close cursors antes de tx/savepoint control.

    /** @internal */
    public function trackPdoStatement(\PDOStatement $stmt): void
    {
        $this->livePdoStatements[spl_object_id($stmt)] = $stmt;
    }

    /** @internal */
    public function untrackPdoStatement(\PDOStatement $stmt): void
    {
        $id = spl_object_id($stmt);
        unset($this->livePdoStatements[$id]);
    }

    /**
     * Cierra cursores de todos los statements vivos. Necesario antes de begin/commit/rollback/savepoint/exec().
     * @internal
     */
    public function closeAllLiveCursors(): void
    {
        foreach ($this->livePdoStatements as $stmt) {
            try {
                $stmt->closeCursor();
            } catch (\Throwable) { /* ignore */
            }
        }
    }

    // ---------------- prepare ----------------

    final public function prepare(string $sql): StatementInterface
    {
        $this->ensureConnected();
        $converted = $this->normalizeToQMarks($sql);
        try {
            $pdoStmt = $this->pdo->prepare($converted);
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this, 'prepare', $sql);
        }
        if ($pdoStmt === false) {
            $e = new \RuntimeException('PDO::prepare returned false');
            throw $this->mapper->map($e, $this, 'prepare', $sql);
        }
        return new AbstractPdoStatement(
            stmt: $pdoStmt,
            owner: $this,
            sql: $converted,
            mapper: $this->makeCallableMapper(),
        );
    }

    /** Normaliza SQL con distintos placeholder styles a '?' (PDO siempre acepta qmarks). */
    final public function normalizeToQMarks(string $sql): string
    {
        if ($this->paramStyle === 'positional_q' || str_contains($sql, '?')) {
            return $sql;
        }
        if ($this->paramStyle === 'positional_$n') {
            return (string)preg_replace('/\$\d+/', '?', $sql);
        }
        if ($this->paramStyle === 'named_colon') {
            return (string)preg_replace('/:\w+/', '?', $sql);
        }
        return $sql;
    }

    private function makeCallableMapper(): ExceptionMapperInterface_Placeholder
    {
        return new class($this->mapper, $this) implements ExceptionMapperInterface_Placeholder {
            public function __construct(
                private readonly ExceptionMapperInterface $inner,
                private readonly ConnectionInterface $connection,
            ) {}
            public function map(\Throwable $native, ConnectionInterface $owner, string $stage, ?string $sql): DbalException
            {
                return $this->inner->map($native, $this->connection, $stage, $sql);
            }
        };
    }

    // ---------------- executeStatement/Query ----------------

    final public function executeStatement(string $sql, array $params = []): \Quantum\Database\Dbal\Value\QueryResult
    {
        $stmt = $this->prepare($sql);
        return $stmt->execute($params);
    }

    final public function executeQuery(string $sql, array $params = []): \Quantum\Database\Dbal\Value\QueryResult
    {
        $stmt = $this->prepare($sql);
        return $stmt->execute($params);
    }

    // ---------------- quoting ----------------

    final public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new DbalException(FK::Validation, 'quote_identifier', message: 'Empty identifier');
        }
        $parts = explode('.', $identifier);
        $q = $this->quoteCharacter;
        $escapedQ = $q . $q;
        $joined = [];
        foreach ($parts as $p) {
            if ($p === '') {
                throw new DbalException(FK::Validation, 'quote_identifier', message: "Invalid identifier: $identifier");
            }
            $joined[] = $q . str_replace($q, $escapedQ, $p) . $q;
        }
        return implode('.', $joined);
    }

    final public function quoteString(string $value): string
    {
        $this->ensureConnected();
        return $this->pdo->quote($value);
    }

    // ---------------- transactions ----------------

    /**
     * Helper wrapper: cierra cursores vivos + ejecuta $pdo->exec(),
     * mapeando excepciones. Útil para sentencias control tx/savepoint/DDL.
     * @throws DbalException
     */
    private function pdoExec(string $sql, string $stage): int
    {
        $this->closeAllLiveCursors();
        try {
            $affected = $this->pdo->exec($sql);
            if ($affected === false) {
                $err = $this->pdo->errorInfo();
                $msg = is_array($err) ? implode(' | ', $err) : 'exec failed';
                throw new \RuntimeException($msg);
            }
            $this->touchLastUsed();
            return $affected;
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this, $stage, $sql);
        }
    }

    final public function inTransaction(): bool
    {
        if (!$this->isConnected()) return false;
        return (bool)$this->pdo->inTransaction();
    }

    final public function beginTransaction(): bool
    {
        $this->ensureConnected();
        if ($this->pdo->inTransaction()) {
            throw new DbalException(FK::Concurrency, 'tx.begin', message: 'Already inside transaction');
        }
        $this->closeAllLiveCursors();
        try {
            $ok = $this->pdo->beginTransaction();
            $this->autoCommitState = false;
            return $ok;
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this, 'tx.begin');
        }
    }

    final public function commit(): bool
    {
        $this->ensureConnected();
        if (!$this->pdo->inTransaction()) {
            throw new DbalException(FK::Internal, 'tx.commit', message: 'No active transaction');
        }
        $this->closeAllLiveCursors();
        try {
            $ok = $this->pdo->commit();
            $this->autoCommitState = true;
            return $ok;
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this, 'tx.commit');
        }
    }

    final public function rollback(): bool
    {
        $this->ensureConnected();
        if (!$this->pdo->inTransaction()) {
            throw new DbalException(FK::Internal, 'tx.rollback', message: 'No active transaction');
        }
        $this->closeAllLiveCursors();
        try {
            $ok = $this->pdo->rollBack();
            $this->autoCommitState = true;
            return $ok;
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this, 'tx.rollback');
        }
    }

    final public function createSavepoint(string $identifier): void
    {
        if (!$this->supportsSavepoints) {
            throw new DbalException(FK::Capability, 'tx.sp.create', message: 'Savepoints not supported');
        }
        $this->ensureConnected();
        $this->pdoExec($this->sqlSavepointCreate($this->sanitizeSpName($identifier)), 'tx.sp.create');
    }

    final public function releaseSavepoint(string $identifier): void
    {
        if (!$this->supportsSavepoints) {
            throw new DbalException(FK::Capability, 'tx.sp.release', message: 'Savepoints not supported');
        }
        $this->ensureConnected();
        $this->pdoExec($this->sqlSavepointRelease($this->sanitizeSpName($identifier)), 'tx.sp.release');
    }

    final public function rollbackToSavepoint(string $identifier): void
    {
        if (!$this->supportsSavepoints) {
            throw new DbalException(FK::Capability, 'tx.sp.rollback', message: 'Savepoints not supported');
        }
        $this->ensureConnected();
        $this->pdoExec($this->sqlSavepointRollback($this->sanitizeSpName($identifier)), 'tx.sp.rollback');
    }

    final protected function sanitizeSpName(string $id): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) {
            throw new DbalException(FK::Validation, 'tx.sp.name', message: 'Invalid savepoint name');
        }
        return $id;
    }

    protected function sqlSavepointCreate(string $name): string
    {
        return "SAVEPOINT {$name}";
    }
    protected function sqlSavepointRelease(string $name): string
    {
        return "RELEASE SAVEPOINT {$name}";
    }
    protected function sqlSavepointRollback(string $name): string
    {
        return "ROLLBACK TO SAVEPOINT {$name}";
    }

    final public function setTransactionIsolation(TransactionIsolation $level): void
    {
        $this->ensureConnected();
        $this->pdoExec($this->sqlSetTxIsolation($level->value), 'tx.isolation');
    }

    protected function sqlSetTxIsolation(string $levelValue): string
    {
        return "SET TRANSACTION ISOLATION LEVEL {$levelValue}";
    }

    // ---------------- metadata ----------------

    final public function getDriverInfo(): DriverInfo
    {
        $this->ensureConnected();
        try {
            $server = (string)@($this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) ?: '');
        } catch (\Throwable) {
            $server = '';
        }
        return new DriverInfo(
            driverName: $this->driverName,
            serverVersion: $server,
            databaseName: (string)($this->config['database'] ?? ''),
            charset: (string)($this->config['charset'] ?? ''),
        );
    }

    final public function getCapabilities(): DatabaseCapabilitySet
    {
        $info = $this->getDriverInfo();
        return DatabaseCapabilitySet::detectFromDriverInfo($this->driverName, $info->serverVersion);
    }

    final public function getNativeHandle(): mixed
    {
        return $this->pdo;
    }

    final public function lastInsertId(?string $sequenceName = null): string|int|null
    {
        $this->ensureConnected();
        try {
            $v = $this->pdo->lastInsertId($sequenceName !== null ? $sequenceName : null);
            if ($v === false || $v === '') return null;
            if (ctype_digit($v)) return (int)$v;
            return $v;
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------------- internals ----------------

    final protected function ensureConnected(): void
    {
        if ($this->pdo === null) {
            $this->connect();
        }
    }
}