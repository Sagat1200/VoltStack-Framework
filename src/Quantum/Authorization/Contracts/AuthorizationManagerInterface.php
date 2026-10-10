<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\BoundAuthorization;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationException;

interface AuthorizationManagerInterface
{
    public function for(mixed $principal): BoundAuthorization;

    /**
     * Construye una BoundAuthorization atada al principal impersonado (tipo
     * ImpersonatedUser) y enriquece el context con trazabilidad originator/target.
     *
     * @param mixed $caller   El principal que INICIA la impersonacion (originator).
     * @param mixed $target   El principal EN NOMBRE DEL CUAL se actua (target).
     * @param Scope|string|null $scope  Scope opcional en el cual la impersonacion es valida.
     *
     * @throws \InvalidArgumentException si caller o target son id vacios o invalidos.
     */
    public function impersonate(mixed $caller, mixed $target, Scope|string|null $scope = null): BoundAuthorization;

    public function check(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool;

    public function cannot(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool;

    public function decide(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult;

    /**
     * @throws AuthorizationException
     */
    public function authorize(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult;
}
