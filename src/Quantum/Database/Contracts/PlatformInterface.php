<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Platform\PlatformCapabilities;

interface PlatformInterface
{
    public function id(): string;

    public function capabilities(): PlatformCapabilities;
}
