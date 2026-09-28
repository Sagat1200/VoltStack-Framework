<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final readonly class AuthenticationMethodReferenceList
{
    /**
     * @var list<string>
     */
    public array $amrValues;

    /**
     * @param iterable<string> $amrValues
     */
    public function __construct(iterable $amrValues = [])
    {
        $seen = [];
        $result = [];
        foreach ($amrValues as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $normalized = trim($value);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = $normalized;
        }
        $this->amrValues = array_values($result);
    }

    public function addAmr(string $amr): self
    {
        $merged = array_merge($this->amrValues, [$amr]);
        return new self($merged);
    }

    public function hasAmr(string $amr): bool
    {
        return in_array($amr, $this->amrValues, true);
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->amrValues;
    }

    public function count(): int
    {
        return count($this->amrValues);
    }
}
