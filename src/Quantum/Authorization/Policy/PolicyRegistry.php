<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

use Quantum\Authorization\Policy\Attributes\HandlesAbility;
use Quantum\Authorization\Policy\Attributes\PolicyFor;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use VoltStack\Framework\Application;

final class PolicyRegistry
{
    /**
     * @var array<string, list<object|class-string>>
     */
    private array $policies = [];

    /**
     * @var array<string, PolicyDescriptor>
     */
    private array $descriptors = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    public function register(string $subjectClass, object|string $policy): void
    {
        $subjectClass = trim($subjectClass);

        if ($subjectClass === '') {
            throw new \InvalidArgumentException('Authorization policy subject class cannot be empty.');
        }

        $this->policies[$subjectClass] ??= [];
        $this->policies[$subjectClass][] = $policy;

        if (is_string($policy) && class_exists($policy)) {
            $this->descriptors[$policy] = $this->descriptorFor($policy, [$subjectClass], PolicySource::Explicit);
        }
    }

    public function registerPolicy(object|string $policy): void
    {
        $policyClass = is_object($policy) ? $policy::class : $policy;

        if (! class_exists($policyClass)) {
            throw new \InvalidArgumentException(sprintf(
                'Authorization policy class [%s] could not be found.',
                $policyClass,
            ));
        }

        $reflection = new ReflectionClass($policyClass);
        $attributes = $reflection->getAttributes(PolicyFor::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new \InvalidArgumentException(sprintf(
                'Authorization policy [%s] must declare at least one #[PolicyFor(...)] attribute or use explicit register(subject, policy).',
                $policyClass,
            ));
        }

        $subjects = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            foreach ((array) $instance->subject as $subject) {
                $normalized = is_string($subject) ? trim($subject) : '';

                if ($normalized === '') {
                    continue;
                }

                $subjects[] = $normalized;
                $this->register($normalized, $policy);
            }
        }

        $this->descriptors[$policyClass] = $this->descriptorFor(
            $policyClass,
            array_values(array_unique($subjects)),
            PolicySource::Attribute,
        );
    }

    /**
     * @param array<class-string, object|class-string|list<object|class-string>> $mappings
     */
    public function registerFromConfig(array $mappings): void
    {
        foreach ($mappings as $subject => $policies) {
            if (is_int($subject)) {
                foreach ((array) $policies as $policy) {
                    $this->registerPolicy($policy);
                }

                continue;
            }

            foreach ((array) $policies as $policy) {
                $this->register($subject, $policy);
            }
        }
    }

    public function resolve(?string $subjectClass): ?object
    {
        $resolved = $this->resolveAll($subjectClass);

        return $resolved[0] ?? null;
    }

    /**
     * @return list<object>
     */
    public function resolveAll(?string $subjectClass): array
    {
        if ($subjectClass === null || $subjectClass === '') {
            return [];
        }

        $entries = $this->policies[$subjectClass] ?? [];

        foreach ($this->policies as $candidate => $policies) {
            if ($candidate === $subjectClass) {
                continue;
            }

            if (is_a($subjectClass, $candidate, true)) {
                foreach ($policies as $policy) {
                    $entries[] = $policy;
                }
            }
        }

        $resolved = [];
        $seen = [];

        foreach ($entries as $policy) {
            $instance = is_string($policy)
                ? $this->app->make($policy)
                : $policy;

            if (! is_object($instance)) {
                continue;
            }

            $class = $instance::class;

            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * @return list<PolicyDescriptor>
     */
    public function descriptors(): array
    {
        return array_values($this->descriptors);
    }

    /**
     * @param list<class-string> $subjects
     * @param class-string $policyClass
     */
    private function descriptorFor(string $policyClass, array $subjects, PolicySource $source): PolicyDescriptor
    {
        $reflection = new ReflectionClass($policyClass);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['__construct', 'before', 'after', 'evaluate', '__invoke'], true)) {
                continue;
            }

            $abilities = [];
            $abilityAttributes = $method->getAttributes(HandlesAbility::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($abilityAttributes as $attribute) {
                $instance = $attribute->newInstance();

                foreach ((array) $instance->abilities as $ability) {
                    if (is_string($ability) && trim($ability) !== '') {
                        $abilities[] = trim($ability);
                    }
                }
            }

            if ($abilities === []) {
                $abilities[] = $method->getName();
            }

            $methods[] = new PolicyMethodDescriptor(
                $method->getName(),
                array_values(array_unique($abilities)),
            );
        }

        return new PolicyDescriptor(
            policyClass: $policyClass,
            subjects: $subjects,
            source: $source,
            methods: $methods,
        );
    }
}
