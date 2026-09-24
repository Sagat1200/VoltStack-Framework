<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationException;

final readonly class BoundAuthorization
{
    public function __construct(
        private AuthorizationManagerInterface $manager,
        private mixed $principal,
    ) {}

    public function check(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): bool
    {
        return $this->manager->check($ability, $subject, $context, $this->principal);
    }

    public function cannot(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): bool
    {
        return $this->manager->cannot($ability, $subject, $context, $this->principal);
    }

    public function decide(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): DecisionResult
    {
        return $this->manager->decide($ability, $subject, $context, $this->principal);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): DecisionResult
    {
        return $this->manager->authorize($ability, $subject, $context, $this->principal);
    }
}
