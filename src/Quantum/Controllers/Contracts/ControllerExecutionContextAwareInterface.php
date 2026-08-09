<?php

declare(strict_types=1);

namespace Quantum\Controllers\Contracts;

use Quantum\Controllers\ControllerExecutionContext;

/**
 * @api Contrato público estable para injectar contexto de ejecución en Controllers.
 *
 * Implementado por defecto por `Quantum\Controllers\Controller` (v0.16.0+).
 * No cambies estas firmas: cualquier Controller customizado que implemente esta interfaz
 * deberá adaptarse a cambios de firmas en MAJOR 2.0.
 */
interface ControllerExecutionContextAwareInterface
{
    public function setControllerExecutionContext(ControllerExecutionContext $context): void;

    public function releaseControllerExecutionContext(): void;
}