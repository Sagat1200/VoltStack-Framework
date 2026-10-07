<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;

/**
 * Contrato semantico del nuevo pipeline.
 *
 * Se introduce sin sustituir al legacy `ExceptionMapperInterface`, que hoy
 * sigue siendo usado por el handler existente del framework.
 */
interface SemanticExceptionMapperInterface
{
    public function map(FailureSnapshot $failure, ExceptionContext $context): ?SemanticError;
}
