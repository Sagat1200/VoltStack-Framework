<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * @internal
 */
final class ScopeStack
{
    /**
     * @var list<ScopeFrame>
     */
    private array $frames = [];

    public function __construct()
    {
        $this->frames[] = new ScopeFrame($this->newId(), 'root');
    }

    public function current(): ScopeFrame
    {
        return $this->frames[array_key_last($this->frames)];
    }

    public function enter(string $name = 'scope'): ScopeFrame
    {
        $frame = new ScopeFrame($this->newId(), $name);
        $this->frames[] = $frame;

        return $frame;
    }

    public function leave(): ScopeFrame
    {
        if (count($this->frames) === 1) {
            $root = $this->current();
            $root->flush();

            return $root;
        }

        /** @var ScopeFrame $frame */
        $frame = array_pop($this->frames);
        $frame->flush();

        return $frame;
    }

    public function flushCurrent(): void
    {
        $this->current()->flush();
    }

    public function hasActiveScope(): bool
    {
        return count($this->frames) > 1;
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
