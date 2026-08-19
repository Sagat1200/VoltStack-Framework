<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[Version]: optimictic locking column (int).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Version
{
    public function __construct() {}
}