<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Contracts\AbilityNormalizerInterface;
use Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface;
use Quantum\Authorization\Contracts\PrincipalResolverInterface;
use Quantum\Authorization\Contracts\SubjectResolverInterface;
use Quantum\Authorization\Context\AuthorizationContext;

final class AuthorizationRequestFactory
{
    public function __construct(
        private readonly AbilityNormalizerInterface $abilityNormalizer,
        private readonly PrincipalResolverInterface $principalResolver,
        private readonly SubjectResolverInterface $subjectResolver,
        private readonly AuthorizationContextFactoryInterface $contextFactory,
    ) {}

    public function create(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): AuthorizationRequest {
        return new AuthorizationRequest(
            $this->abilityNormalizer->normalize($ability),
            $this->principalResolver->resolve($principal),
            $this->subjectResolver->describe($subject),
            $this->contextFactory->create($context),
        );
    }
}
