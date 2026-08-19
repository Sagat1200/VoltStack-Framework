<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[DiscriminatorValue] en una subentidad (Inheritance hierarchy).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class DiscriminatorValue
{
    public function __construct(public string $value) {}
}
