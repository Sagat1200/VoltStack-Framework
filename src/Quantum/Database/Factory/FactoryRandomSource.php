<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

final class FactoryRandomSource
{
    private int $state;

    public function __construct(private readonly int $seed)
    {
        $this->state = $seed !== 0 ? $seed : 0x6D2B79F5;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function int(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \InvalidArgumentException('Factory random range is invalid.');
        }

        if ($min === $max) {
            return $min;
        }

        return $min + ($this->nextUInt32() % (($max - $min) + 1));
    }

    public function bool(): bool
    {
        return ($this->nextUInt32() & 1) === 1;
    }

    /**
     * @template T
     * @param list<T> $values
     * @return T
     */
    public function pick(array $values): mixed
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Factory random pick requires at least one value.');
        }

        return $values[$this->int(0, count($values) - 1)];
    }

    public function slug(string $prefix = 'item', int $suffixLength = 8): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';

        for ($index = 0; $index < $suffixLength; $index++) {
            $suffix .= $alphabet[$this->int(0, strlen($alphabet) - 1)];
        }

        return rtrim($prefix, '-') . '-' . $suffix;
    }

    public function email(string $prefix = 'user', string $domain = 'example.test'): string
    {
        return sprintf('%s+%s@%s', $prefix, $this->slug('seed', 6), $domain);
    }

    private function nextUInt32(): int
    {
        $state = $this->state & 0xFFFFFFFF;
        $state ^= ($state << 13) & 0xFFFFFFFF;
        $state ^= ($state >> 17);
        $state ^= ($state << 5) & 0xFFFFFFFF;

        $this->state = $state & 0xFFFFFFFF;

        return $this->state;
    }
}