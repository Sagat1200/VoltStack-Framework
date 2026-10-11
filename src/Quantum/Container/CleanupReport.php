<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * Reporte de cierre de un scope o worker.
 *
 * `close()` retorna este DTO en lugar de lanzar excepciones por fallos de
 * disposers, para permitir cerrar todos los niveles en un `finally`. El
 * adaptador que abre el worker DEBE inspeccionar `ok()` y marcar la salud
 * del runtime cuando `poisoned` sea verdadero.
 *
 * @internal
 */
final readonly class CleanupReport
{
    /**
     * @param array<int, string> $errors
     */
    public function __construct(
        public string $frameId,
        public ScopeKind $kind,
        public int $resolvedInstanceCount = 0,
        public array $errors = [],
        public bool $poisoned = false,
    ) {
    }

    public function ok(): bool
    {
        return $this->errors === [] && !$this->poisoned;
    }

    /**
     * @return array{frame_id: string, kind: string, resolved_instance_count: int, errors: list<string>, poisoned: bool, ok: bool}
     */
    public function toArray(): array
    {
        return [
            'frame_id' => $this->frameId,
            'kind' => $this->kind->value,
            'resolved_instance_count' => $this->resolvedInstanceCount,
            'errors' => $this->errors,
            'poisoned' => $this->poisoned,
            'ok' => $this->ok(),
        ];
    }
}
