<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * Especifica la columna FK de una asociación to-one owning-side (ManyToOne, OneToOne owning).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class JoinColumn
{
    public function __construct(
        public ?string $name = null,
        public ?string $referencedColumn = null,
        public bool $nullable = true,
        public bool $unique = false,
    ) {}
}
