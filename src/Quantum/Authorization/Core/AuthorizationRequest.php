<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Subject\SubjectDescriptor;

final readonly class AuthorizationRequest
{
    public function __construct(
        private Ability $ability,
        private PrincipalInterface $principal,
        private SubjectDescriptor $subject,
        private AuthorizationContext $context,
    ) {}

    public function ability(): Ability
    {
        return $this->ability;
    }

    public function principal(): PrincipalInterface
    {
        return $this->principal;
    }

    public function subject(): SubjectDescriptor
    {
        return $this->subject;
    }

    public function context(): AuthorizationContext
    {
        return $this->context;
    }
}
