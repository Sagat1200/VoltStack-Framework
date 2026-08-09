<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\ExceptionHandlingResult;
use Throwable;

/**
 * @api Contrato público estable del Exception Handler global (RFC 9457 compatible).
 *
 * La implementación por defecto es `Quantum\Exceptions\ExceptionHandler`;
 * para customizar el manejo de excepciones en producción implementa esta interfaz.
 */
interface ExceptionHandlerInterface
{
    public function handle(Throwable $throwable, ExceptionHandlingContext $context): ExceptionHandlingResult;
}
