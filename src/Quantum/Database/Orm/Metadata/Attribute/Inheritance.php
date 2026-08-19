<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[Inheritance] SINGLE_TABLE o JOINED.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Inheritance
{
    /**
     * @param string $type SINGLE_TABLE | JOINED
     * @param array<string,class-string> $map discriminator → entity class
     */
    public function __construct(
        public string $type = 'SINGLE_TABLE',
        public string $discriminatorColumn = 'discr',
        public array $map = [],
    ) {}
}
