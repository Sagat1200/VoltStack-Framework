<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[Id]: marca la propiedad como parte de la primary key.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Id
{
    /**
     * @param ?string $strategy AUTO|SEQUENCE|IDENTITY|UUID|NONE o null (default driver)
     * @param ?class-string $generatorClass custom Id generator
     */
    public function __construct(
        public ?string $strategy = null,
        public ?string $sequenceName = null,
        public ?string $generatorClass = null,
    ) {}
}
