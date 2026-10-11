<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * Token opaco que identifica un scope activo dentro del runtime del contenedor.
 *
 * Se pasa a los callbacks ejecutados por `ScopeHandle::run()` para que puedan
 * inspeccionar el frame activo sin acceder directamente al stack interno.
 *
 * @internal
 */
final readonly class ScopeToken
{
    public function __construct(
        public string $frameId,
        public ScopeKind $kind,
        public ?string $parentId,
        public int $depth,
    ) {
    }

    public static function fromFrame(ScopeFrame $frame): self
    {
        return new self(
            frameId: $frame->id(),
            kind: $frame->kind(),
            parentId: $frame->parentId(),
            depth: $frame->depth(),
        );
    }

    /**
     * @return array{frame_id: string, kind: string, parent_id: ?string, depth: int}
     */
    public function toArray(): array
    {
        return [
            'frame_id' => $this->frameId,
            'kind' => $this->kind->value,
            'parent_id' => $this->parentId,
            'depth' => $this->depth,
        ];
    }
}
