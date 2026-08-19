<?php declare(strict_types=1);

namespace Quantum\Database\Query\Expression;

/**
 * Composite AND/OR/NOT de expresiones para el Query Builder.
 * Immutable. __toString() produce SQL human-readable con parámetros :name (para logs, no execute).
 */
final readonly class CompositeExpression implements \Stringable
{
    public const TYPE_AND = 'AND';
    public const TYPE_OR  = 'OR';
    public const TYPE_NOT = 'NOT';

    /**
     * @param string $type AND | OR | NOT
     * @param list<string|CompositeExpression> $parts
     */
    public function __construct(
        public string $type,
        public array  $parts = [],
    ) {}

    /**
     * Añade una parte y devuelve nueva instancia (inmutable).
     */
    public function with(string|self $expr): self
    {
        return new self($this->type, [...$this->parts, $expr]);
    }

    /**
     * Render parenthesizado para logging. NUNCA se envía al DB directo.
     */
    public function __toString(): string
    {
        if ($this->parts === []) {
            return $this->type === self::TYPE_NOT ? '' : '1=1';
        }

        $parts = array_map(
            fn(string|self $p): string => $p instanceof self ? "({$p})" : (string)$p,
            $this->parts,
        );

        if ($this->type === self::TYPE_NOT) {
            $inner = $parts[0];
            return "NOT ({$inner})";
        }

        $sep = ' ' . $this->type . ' ';
        return '(' . implode($sep, $parts) . ')';
    }
}
