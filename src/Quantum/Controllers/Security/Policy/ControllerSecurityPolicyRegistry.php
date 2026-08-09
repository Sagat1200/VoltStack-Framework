<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Policy;

use Quantum\Controllers\Security\Attributes\PolicyClass;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;
use Quantum\Controllers\Security\Policy\Composition\PolicyExpressionResolver;
use ReflectionAttribute;
use ReflectionClass;

final class ControllerSecurityPolicyRegistry implements ControllerSecurityPolicyRegistryInterface
{
    /** @var array<string, ControllerSecurityPolicyInterface> */
    private array $policies = [];

    /** @var array<string, class-string<ControllerSecurityPolicyInterface>|callable(): ControllerSecurityPolicyInterface> */
    private array $lazyDefinitions = [];

    private bool $isFrozen = false;

    public function __construct(
        private readonly ?PolicyExpressionResolver $expressionResolver = null,
    ) {}

    public function register(ControllerSecurityPolicyInterface $policy): void
    {
        if ($this->isFrozen) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Cannot register security policy [%s]: registry is frozen.',
                $policy->id(),
            ));
        }
        $this->policies[$policy->id()] = $policy;
    }

    public function registerClass(string $policyClass, ?callable $factory = null): void
    {
        if ($this->isFrozen) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Cannot register security policy class [%s]: registry is frozen.',
                $policyClass,
            ));
        }
        if (! is_string($policyClass) || $policyClass === '') {
            throw new SecurityInfrastructureFailureException('Policy class must be a non-empty string class-string.');
        }
        if (! class_exists($policyClass)) {
            throw new SecurityInfrastructureFailureException(sprintf('Policy class [%s] does not exist.', $policyClass));
        }
        if (! is_subclass_of($policyClass, ControllerSecurityPolicyInterface::class)) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Policy class [%s] must implement %s.',
                $policyClass,
                ControllerSecurityPolicyInterface::class,
            ));
        }

        $reflection = new ReflectionClass($policyClass);
        $id = $policyClass;
        try {
            $attr = $reflection->getAttributes(PolicyClass::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if ($attr !== null) {
                $inst = $attr->newInstance();
                if ($inst->id !== null && $inst->id !== '') {
                    $id = $inst->id;
                }
            }
        } catch (\Throwable) {
        }

        $this->lazyDefinitions[$id] = $factory ?? $policyClass;
    }

    /**
     * Register a string expression, which may be a plain policy id or a boolean expression.
     *   - Strings WITHOUT &&/||/!/() → lookup existing lazy policy id via registry entries
     *   - Strings WITH operators → parse with expression parser (allOf/anyOf/not via PolicyExpressionResolver)
     */
    public function registerExpression(string $expressionOrId, ?string $overrideId = null): void
    {
        if ($this->isFrozen) {
            throw new SecurityInfrastructureFailureException(sprintf(
                'Cannot register expression [%s]: registry is frozen.',
                $expressionOrId,
            ));
        }
        $resolver = $this->expressionResolver ?? PolicyExpressionResolver::default();
        $hasOperators = preg_match('#(&&|\|\||!|\(|\))#', $expressionOrId) === 1;
        if (!$hasOperators && isset($this->lazyDefinitions[$expressionOrId])) {
            return;
        }
        $useAsId = $overrideId ?? $expressionOrId;
        if ($hasOperators) {
            $policy = $resolver->parse($expressionOrId, $useAsId);
            if ($policy->id() !== $useAsId) {
                $compositeChildren = $policy instanceof \Quantum\Controllers\Security\Policy\Composition\CompositePolicy
                    ? $policy->children
                    : [$policy];
                $class = get_class($policy);
                try {
                    $r = new \ReflectionClass($class);
                    if ($r->isSubclassOf(\Quantum\Controllers\Security\Policy\Composition\AnyOfPolicy::class)) {
                        $policy = new \Quantum\Controllers\Security\Policy\Composition\AnyOfPolicy($useAsId, $compositeChildren);
                    } elseif ($r->isSubclassOf(\Quantum\Controllers\Security\Policy\Composition\AllOfPolicy::class)) {
                        $policy = new \Quantum\Controllers\Security\Policy\Composition\AllOfPolicy($useAsId, $compositeChildren);
                    } elseif ($r->isSubclassOf(\Quantum\Controllers\Security\Policy\Composition\NotPolicy::class)) {
                        $policy = new \Quantum\Controllers\Security\Policy\Composition\NotPolicy($useAsId, $compositeChildren);
                    } else {
                        $policy = new \Quantum\Controllers\Security\Policy\Composition\AnyOfPolicy($useAsId, $compositeChildren);
                    }
                } catch (\Throwable) {
                    $policy = new \Quantum\Controllers\Security\Policy\Composition\AnyOfPolicy($useAsId, $compositeChildren);
                }
            }
        } else {
            $policy = $resolver->resolveSingle($expressionOrId);
            if ($useAsId !== null && $useAsId !== '' && $useAsId !== $policy->id()) {
                $policy = new \Quantum\Controllers\Security\Policy\Composition\ExpressionTermPolicy($useAsId, trim($expressionOrId));
            }
        }
        $this->register($policy);
    }

    public function resolve(string $policyId): ControllerSecurityPolicyInterface
    {
        if (isset($this->policies[$policyId])) {
            return $this->policies[$policyId];
        }

        if (isset($this->lazyDefinitions[$policyId])) {
            $def = $this->lazyDefinitions[$policyId];
            if (is_callable($def)) {
                $instance = $def();
            } else {
                if (! class_exists($def)) {
                    throw new SecurityInfrastructureFailureException(sprintf(
                        'Security policy lazy class [%s] does not exist at resolve time.',
                        $def,
                    ));
                }
                $instance = new $def();
            }

            if (! $instance instanceof ControllerSecurityPolicyInterface) {
                throw new SecurityInfrastructureFailureException(sprintf(
                    'Lazy-resolved policy [%s] does not implement %s.',
                    $policyId,
                    ControllerSecurityPolicyInterface::class,
                ));
            }

            if ($instance->id() !== $policyId) {
                throw new SecurityInfrastructureFailureException(sprintf(
                    'Lazy policy [%s] reports id [%s]; expected [%s]. Policies must report the same id they were registered under (or declare #[PolicyClass(id: "...")]).',
                    $policyId,
                    $instance->id(),
                    $policyId,
                ));
            }

            $this->policies[$policyId] = $instance;
            unset($this->lazyDefinitions[$policyId]);

            return $instance;
        }

        throw new SecurityInfrastructureFailureException(sprintf(
            'Security policy [%s] not registered.',
            $policyId,
        ));
    }

    /** @return iterable<ControllerSecurityPolicyInterface> */
    public function all(): iterable
    {
        foreach (array_keys($this->lazyDefinitions) as $lazyId) {
            $this->resolve($lazyId);
        }
        return array_values($this->policies);
    }

    public function freeze(): void
    {
        $this->isFrozen = true;
    }

    public function frozen(): bool
    {
        return $this->isFrozen;
    }

    public function count(): int
    {
        return count($this->policies) + count($this->lazyDefinitions);
    }
}
