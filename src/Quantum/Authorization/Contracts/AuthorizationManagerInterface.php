<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\BoundAuthorization;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationException;

interface AuthorizationManagerInterface
{
    public function for(mixed $principal): BoundAuthorization;

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
