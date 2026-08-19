<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[Embedded] (V1: alias a VO dentro de columna(s)).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Embedded
{
    /** @param class-string $class VO a mapear */
    public function __construct(
        public string $class,
        public ?string $prefix = null,
    ) {}
}
