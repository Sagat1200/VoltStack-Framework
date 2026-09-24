<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

final readonly class RuntimeBindingSet
{
    /**
     * @param array<int|string, mixed> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function normalized(): array
    {
        $normalized = [];

        foreach ($this->values as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function get(int|string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
