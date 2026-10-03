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
        $this->frames[] = new ScopeFrame(
            id: $this->newId(),
            name: 'root',
            kind: ScopeKind::Root,
            parentId: null,
            depth: 0,
        );
    }

    public function current(): ScopeFrame
    {
        return $this->frames[array_key_last($this->frames)];
    }

    public function enter(string $name = 'scope'): ScopeFrame
    {
        $current = $this->current();
        $frame = new ScopeFrame(
            id: $this->newId(),
            name: $name,
            kind: ScopeKind::fromName($name),
            parentId: $current->id(),
            depth: $current->depth() + 1,
        );
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

    public function currentKind(): ScopeKind
    {
        return $this->current()->kind();
    }

    public function currentName(): string
    {
        return $this->current()->name();
    }

    public function findClosestByKind(ScopeKind $kind): ?ScopeFrame
    {
        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            $frame = $this->frames[$index];

            if ($frame->kind() === $kind) {
                return $frame;
            }
        }

        return null;
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
