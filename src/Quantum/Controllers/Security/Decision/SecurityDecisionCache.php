<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Decision;

use Quantum\Controllers\Security\Contracts\SecurityDecisionCacheInterface;

final class SecurityDecisionCache implements SecurityDecisionCacheInterface
{
    /** @var array<string, SecurityDecision> */
    private array $items = [];

    public function __construct(
        public readonly int $maxItems = 128,
    ) {}

    public function get(SecurityDecisionKey $key): ?SecurityDecision
    {
        return $this->items[$key->hash()] ?? null;
    }

    public function put(SecurityDecisionKey $key, SecurityDecision $decision): void
    {
        $hash = $key->hash();
        if (! isset($this->items[$hash]) && count($this->items) >= $this->maxItems) {
            array_shift($this->items);
        }
        $this->items[$hash] = $decision;
    }

    public function clear(): int
    {
        $count = count($this->items);
        $this->items = [];

        return $count;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
