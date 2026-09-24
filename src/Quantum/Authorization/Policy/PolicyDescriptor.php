<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

final readonly class PolicyDescriptor
{
    /**
     * @param class-string $policyClass
     * @param list<class-string> $subjects
     * @param list<PolicyMethodDescriptor> $methods
     */
    public function __construct(
        public string $policyClass,
        public array $subjects,
        public PolicyType $type = PolicyType::Resource,
        public PolicySource $source = PolicySource::Explicit,
        public array $methods = [],
    ) {}

    public function handles(string $ability): bool
    {
        if ($this->methods === []) {
            return true;
        }

        foreach ($this->methods as $method) {
            if ($method->handles($ability)) {
                return true;
            }
        }

        return false;
    }
}
