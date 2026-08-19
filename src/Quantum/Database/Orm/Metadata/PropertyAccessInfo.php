<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * PropertyAccessInfo: reflection info cacheado en compile time para acceso
 * rápido en runtime hot path (hydration, change tracking, read/write prop).
 */
final readonly class PropertyAccessInfo
{
    public function __construct(
        public bool $isPublicRead,
        public bool $isPublicWrite,
        public ?string $getter  = null,  // getXxx | isXxx | hasXxx
        public ?string $setter  = null,  // setXxx
        public ?string $hasser  = null,  // hasXxx
        public ?string $adder   = null,  // addXxx (collections)
        public ?string $remover = null,  // removeXxx
    ) {}
}
