<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\PdoCommon;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Exception\DbalException;

/**
 * Interfaz interna (V1) para hacer el Statement agnóstico al ExceptionMapperInterface completo.
 * Reduce acoplamiento entre Statement y ExceptionMapper.
 */
interface ExceptionMapperInterface_Placeholder
{
    public function map(\Throwable $native, ConnectionInterface $owner, string $stage, ?string $sql): DbalException;
}
