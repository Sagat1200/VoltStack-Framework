<?php declare(strict_types=1);

namespace Quantum\Database\Dbal;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Exception\DbalException;

/**
 * Mapea excepciones nativas del driver → DbalException tipificada.
 * Cada driver implementa el suyo.
 */
interface ExceptionMapperInterface
{
    public function map(
        \Throwable $native,
        ConnectionInterface $connection,
        string $stage,
        ?string $sql = null,
    ): DbalException;

    /**
     * @param DbalException $ex (debe haber sido generado por este mapper)
     */
    public function isRetryable(DbalException $ex): bool;
}
