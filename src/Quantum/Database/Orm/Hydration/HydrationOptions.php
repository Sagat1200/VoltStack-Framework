<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Hydration;

/**
 * Opciones de hydration, VO inmutable.
 */
final readonly class HydrationOptions
{
    public function __construct(
        public bool     $excludeSoftDeleted = true,
        public bool     $refreshOverride = false,
        public bool     $indexById = false,
        public ?\Closure $postHydrate = null,
        public bool     $autoCastFromDb = true,
    ) {}

    public static function defaults(): self
    {
        static $d = null;
        return $d ??= new self();
    }
}
