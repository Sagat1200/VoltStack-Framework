<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * Especifica la tabla pivot para una asociación ManyToMany (owning side).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class JoinTable
{
    public function __construct(
        public ?string $name = null,
        public ?string $joinColumn = null,
        public ?string $inverseJoinColumn = null,
    ) {}
}
