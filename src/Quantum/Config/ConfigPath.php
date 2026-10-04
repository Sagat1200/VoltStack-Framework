<?php

declare(strict_types=1);

namespace Quantum\Config;

use InvalidArgumentException;

final readonly class ConfigPath
{
    /**
     * @var list<string>
     */
    private array $segments;

    private function __construct(private string $value, array $segments)
    {
        $this->segments = $segments;
    }

    public static function fromString(string $path): self
    {
        $normalized = trim($path);

        if ($normalized === '') {
            throw new InvalidArgumentException('Configuration path cannot be empty.');
        }

        if (str_contains($normalized, '..')) {
            throw new InvalidArgumentException('Configuration path cannot contain empty segments.');
        }

        $segments = explode('.', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('Configuration path cannot contain empty segments.');
            }
        }

        return new self($normalized, $segments);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function parent(): ?self
    {
        if (count($this->segments) === 1) {
            return null;
        }

        $segments = $this->segments;
        array_pop($segments);

        return new self(
            implode('.', $segments),
            $segments,
        );
    }

    public function append(string $segment): self
    {
        $normalized = trim($segment);

        if ($normalized === '' || str_contains($normalized, '.')) {
            throw new InvalidArgumentException('Configuration segment must be a non-empty atomic token.');
        }

        $segments = [...$this->segments, $normalized];

        return new self(
            implode('.', $segments),
            $segments,
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
