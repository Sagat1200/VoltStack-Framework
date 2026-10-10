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
        private ?AuthorizationContext $context = null,
    ) {}

    private function mergeContext(?AuthorizationContext $context): ?AuthorizationContext
    {
        if ($this->context === null) {
            return $context;
        }

        if ($context === null) {
            return $this->context;
        }

        return $this->context->mergeAttributes($context->attributes());
    }

    public function check(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): bool
    {
        return $this->manager->check($ability, $subject, $this->mergeContext($context), $this->principal);
    }

    public function cannot(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): bool
    {
        return $this->manager->cannot($ability, $subject, $this->mergeContext($context), $this->principal);
    }

    public function decide(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): DecisionResult
    {
        return $this->manager->decide($ability, $subject, $this->mergeContext($context), $this->principal);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(string|Ability $ability, mixed $subject = null, ?AuthorizationContext $context = null): DecisionResult
    {
        return $this->manager->authorize($ability, $subject, $this->mergeContext($context), $this->principal);
    }
}
