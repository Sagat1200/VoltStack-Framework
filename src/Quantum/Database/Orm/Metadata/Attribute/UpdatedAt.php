<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[UpdatedAt]: automatic update timestamp.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class UpdatedAt
{
    public function __construct(public string $column = 'updated_at') {}
}
