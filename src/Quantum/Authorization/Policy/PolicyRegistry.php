<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

final class PolicyRegistry
{
    /**
     * @var array<string, object>
     */
    private array $policies = [];

    public function register(string $subjectClass, object $policy): void
    {
        $subjectClass = trim($subjectClass);

        if ($subjectClass === '') {
            throw new \InvalidArgumentException('Authorization policy subject class cannot be empty.');
        }

        $this->policies[$subjectClass] = $policy;
    }

    public function resolve(?string $subjectClass): ?object
    {
        if ($subjectClass === null || $subjectClass === '') {
            return null;
        }

        if (isset($this->policies[$subjectClass])) {
            return $this->policies[$subjectClass];
        }

        foreach ($this->policies as $candidate => $policy) {
            if (is_a($subjectClass, $candidate, true)) {
                return $policy;
            }
        }

        return null;
    }
}
