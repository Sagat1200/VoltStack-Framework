<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[CreatedAt]: automático, fecha de creación.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class CreatedAt
{
    public function __construct(
        public string $column = 'created_at',
        public bool $onUpdate = false,
    ) {}
}
