<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Ability\Ability;

/**
 * Representa un permiso concreto y atómico dentro del modelo de autoridad.
 *
 * Un Permission es el concepto de primer nivel que mapea directamente a una
 * `Ability` (name). Los grants del AuthorityRepository resuelven en ultima
 * instancia un listado de Permission.
 *
 * La clase es inmutable y autocontenida.
 */
final readonly class Permission implements \Stringable
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
        if (trim($this->name) === '') {
            throw new \InvalidArgumentException('Permission name cannot be empty.');
        }
    }

    public static function from(Ability|string|self $ability): self
    {
        if ($ability instanceof self) {
            return $ability;
        }

        $name = $ability instanceof Ability
            ? $ability->name()
            : trim((string) $ability);

        return new self($name);
    }

    public function toAbility(): Ability
    {
        return new Ability($this->name);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->name, $other->name) === 0;
    }

    public function matches(self|Ability|string $other): bool
    {
        $otherName = match (true) {
            $other instanceof self => $other->name,
            $other instanceof Ability => $other->name(),
            default => trim((string) $other),
        };

        if (strcasecmp($this->name, $otherName) === 0) {
            return true;
        }

        // Wildcard: `admin:*` matches `admin:anything`. `*` matches everything.
        $selfEscaped = preg_quote($this->name, '#');
        $pattern = '#^' . str_replace('\*', '.*', $selfEscaped) . '$#i';

        return (bool) @preg_match($pattern, $otherName);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * @return array{name:string,description:string|null,metadata:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }
}
