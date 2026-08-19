<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Value;

/**
 * Mapea posición 0-based de los parámetros del SQL emitido →
 * (fuente, span opcional) para facilitar diagnóstico.
 */
final readonly class SourceMapEntry
{
    public function __construct(
        public string $source,
        public int $paramIndex,
        public ?int $sourceLine = null,
        public ?string $nodeId = null,
    ) {}
}
