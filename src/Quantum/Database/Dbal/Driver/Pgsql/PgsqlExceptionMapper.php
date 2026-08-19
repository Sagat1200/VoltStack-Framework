<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Pgsql;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind as FK;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\ExceptionMapperInterface;

final class PgsqlExceptionMapper implements ExceptionMapperInterface
{
    public function map(\Throwable $native, ConnectionInterface $connection, string $stage, ?string $sql = null): DbalException
    {
        $msg = DbalException::redactMessage($native->getMessage());
        $sqlstate = ($native instanceof \PDOException) ? (string)($native->errorInfo[0] ?? $native->getCode()) : (string)$native->getCode();
        $driverCode = ($native instanceof \PDOException) ? (int)($native->errorInfo[1] ?? 0) : 0;
        $driverMsg  = ($native instanceof \PDOException) ? (string)($native->errorInfo[2] ?? $msg) : $msg;

        $kind = match (true) {
            str_starts_with($sqlstate, '08')              => FK::Connectivity,
            str_starts_with($sqlstate, '57P01')           => FK::Connectivity, // admin_shutdown
            in_array($sqlstate, ['25P01','25P02'], true)  => FK::Concurrency,  // no_active_sql / in_failed_sql
            in_array($sqlstate, ['40001','40P01'], true)  => FK::Concurrency,  // serialization_failure / deadlock_detected
            str_starts_with($sqlstate, '23')              => FK::Integrity,
            in_array($sqlstate, ['28000','28P01','42501'], true) => FK::Authorization,
            str_starts_with($sqlstate, '42')              => FK::Validation,
            in_array($sqlstate, ['57014'], true)          => FK::Timeout,      // query_canceled
            in_array($sqlstate, ['0A000'], true)          => FK::Capability,
            str_starts_with($sqlstate, '08')              => FK::Connectivity,
            default                                       => FK::Internal,
        };

        $retry = in_array($kind, [FK::Concurrency, FK::Connectivity, FK::Timeout], true);
        return DbalException::wrap(new \RuntimeException($driverMsg, $driverCode, $native),
            $kind, $stage, $sql, $retry, extraMsg: "[pg sqlstate={$sqlstate}]");
    }

    public function isRetryable(DbalException $ex): bool { return $ex->retryable; }
}
