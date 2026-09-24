<?php

declare(strict_types=1);

namespace Quantum\Database\Platform;

use Quantum\Database\Contracts\PlatformInterface;

final readonly class SqlitePlatform implements PlatformInterface
{
    public function __construct(
        private PlatformCapabilities $capabilities = new PlatformCapabilities([
            'transactions' => true,
            'savepoints' => true,
            'returning' => true,
            'cte' => true,
            'json' => true,
        ]),
    ) {
    }

    public function id(): string
    {
        return 'sqlite';
    }

    public function capabilities(): PlatformCapabilities
    {
        return $this->capabilities;
    }
}
