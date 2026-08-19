<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Sqlite;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind as FK;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\ExceptionMapperInterface;
use Quantum\Database\Dbal\Driver\PdoCommon\AbstractPdoConnection;

/**
 * PDOException Mapper para SQLite.
 * Falla sin driver code numérico — guíase por mensaje y SQLSTATE.
 */
final class SqliteExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $native, ConnectionInterface $connection, string $stage, ?string $sql = null): DbalException
    {
        $msg = DbalException::redactMessage($native->getMessage());
        $sqlstate = ($native instanceof \PDOException) ? (string)($native->errorInfo[0] ?? $native->getCode()) : (string)$native->getCode();
        $driverCode = ($native instanceof \PDOException) ? ($native->errorInfo[1] ?? 0) : 0;
        $driverMsg = ($native instanceof \PDOException) ? (string)($native->errorInfo[2] ?? $msg) : $msg;

        $kind = match(true) {
            str_contains($sqlstate, '08')
                => FK::Connectivity,
            in_array($sqlstate, ['HY000'], true) && preg_match('/(database is locked|database schema is locked|busy)/i', $driverMsg)
                => FK::Concurrency,
            in_array($sqlstate, ['23000', '23505', '23502', '23503', '23514'], true)
                || str_starts_with($sqlstate, '23')
                || preg_match('/(unique|constraint|foreign key|check constraint|not null)/i', $driverMsg)
                => FK::Integrity,
            in_array($sqlstate, ['28000', '28'], true) || stripos($driverMsg, 'auth') !== false
                => FK::Authorization,
            str_starts_with($sqlstate, '42') || str_starts_with($sqlstate, 'HY0') && preg_match('/(syntax|no such|column|table)/i', $driverMsg)
                => FK::Validation,
            preg_match('/(timeout|timed out)/i', $driverMsg)
                => FK::Timeout,
            default => FK::Internal,
        };

        $retry = in_array($kind, [FK::Concurrency, FK::Connectivity, FK::Timeout], true)
            || preg_match('/(database is locked|busy|schema is locked)/i', $driverMsg);

        return DbalException::wrap(new \RuntimeException($driverMsg, is_numeric($driverCode) ? (int)$driverCode : 0, $native),
            $kind, $stage, $sql, $retry, extraMsg: "[sqlite sqlstate={$sqlstate}]");
    }

    public function isRetryable(DbalException $ex): bool
    {
        return $ex->retryable;
    }
}
