<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[GeneratedValue]: la propiedad tiene valor generado.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class GeneratedValue
{
    public function __construct(public string $strategy = 'AUTO') {}
}
