<?php

declare(strict_types=1);

namespace Quantum\Database\Platform;

use RuntimeException;

final readonly class PlatformCapabilities
{
    /**
     * @param array<string, bool> $items
     */
    public function __construct(
        private array $items = [],
    ) {
    }

    public function supports(string $capability): bool
    {
        return $this->items[$capability] ?? false;
    }

    public function require(string $capability): void
    {
        if ($this->supports($capability)) {
            return;
        }

        throw new RuntimeException(sprintf('Database platform does not support capability [%s].', $capability));
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->items;
    }
}
