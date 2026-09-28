<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

/**
 * Representa un scope de autoridad con jerarquía simple (organization/workspace/project).
 *
 * La jerarquía se expresa como cadena separada por `:` con tokens ordenados de
 * mayor a menor granularidad (por ejemplo `org:acme:workspace:engineering`).
 * Un scope `A` contiene a `B` si A es prefijo exacto de B y sus tokens son
 * idénticos hasta el tamaño de A.
 *
 * El scope `*` (wildcard) se trata como el scope universal que contiene a
 * cualquier otro scope. El scope vacío se normaliza a `global`.
 */
final readonly class Scope implements \Stringable
{
    public const GLOBAL = 'global';
    public const WILDCARD = '*';
    public const SEPARATOR = ':';

    /** @var list<string> */
    public array $tokens;

    public function __construct(
        public string $name,
    ) {
        $trimmed = trim($name);

        if ($trimmed === '') {
            $trimmed = self::GLOBAL;
        }

        if ($trimmed === self::WILDCARD) {
            $this->tokens = [self::WILDCARD];
        } else {
            $raw = explode(self::SEPARATOR, $trimmed);
            $this->tokens = array_values(array_filter(
                array_map('trim', $raw),
                static fn (string $token): bool => $token !== '',
            ));
        }
    }

    public static function global(): self
    {
        return new self(self::GLOBAL);
    }

    public static function wildcard(): self
    {
        return new self(self::WILDCARD);
    }

    /**
     * @param string ...$parts
     */
    public static function compose(string ...$parts): self
    {
        return new self(implode(self::SEPARATOR, array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    public function isWildcard(): bool
    {
        return $this->tokens === [self::WILDCARD];
    }

    /**
     * Indica si este scope contiene (es prefijo jerárquico) a $other.
     */
    public function contains(self $other): bool
    {
        if ($this->isWildcard()) {
            return true;
        }

        if ($other->isWildcard()) {
            return false;
        }

        if (count($this->tokens) > count($other->tokens)) {
            return false;
        }

        foreach ($this->tokens as $index => $token) {
            if (! isset($other->tokens[$index])) {
                return false;
            }

            if ($other->tokens[$index] !== $token && $token !== self::WILDCARD) {
                return false;
            }
        }

        return true;
    }

    public function equals(self $other): bool
    {
        return $this->tokens === $other->tokens;
    }

    public function parent(): ?self
    {
        if ($this->isWildcard()) {
            return null;
        }

        if (count($this->tokens) <= 1) {
            return new self(self::GLOBAL);
        }

        $parentTokens = array_slice($this->tokens, 0, -1);

        return new self(implode(self::SEPARATOR, $parentTokens));
    }

    public function level(): int
    {
        if ($this->isWildcard()) {
            return -1;
        }

        return count($this->tokens);
    }

    public function __toString(): string
    {
        return $this->isWildcard() ? self::WILDCARD : implode(self::SEPARATOR, $this->tokens);
    }

    /**
     * @return array{name:string,tokens:list<string>,level:int}
     */
    public function toArray(): array
    {
        return [
            'name' => (string) $this,
            'tokens' => $this->tokens,
            'level' => $this->level(),
        ];
    }
}
