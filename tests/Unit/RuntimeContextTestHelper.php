<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use VoltStack\Runtime\Context\RuntimeContext;

/**
 * @internal
 */
final class RuntimeContextTestHelper
{
    public static function resetStack(): void
    {
        // Limpia el estado estatico global de RuntimeContext para evitar contaminacion entre tests.
        RuntimeContext::setCurrent(null);
    }
}
