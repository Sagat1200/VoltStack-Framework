<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Enum\NodeFlag;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;

/**
 * Nodo SQG autoritativo. Todas las implementaciones en Node/* son inmutables (readonly/frozen).
 *
 * **IMPORTANTE** (DDD-V1-03 §5):
 * - `id` es único global dentro del mismo SemanticQueryGraph (generado en compilación).
 * - `kind` tipifica la clase concreta (switch/match sobre Kind, sin instanceof).
 * - `children()` devuelve hijos SemanticNode[] (BFS traversal).
 * - `accept(NodeVisitor $v)` implementa doble dispatch para compilación/validación.
 * - `flags` es un bitset de NodeFlag para short-circuit checks en validación.
 */
interface SemanticNode
{
    public function id(): string;
    public function kind(): SemanticNodeKind;
    public function flags(): int;
    public function hasFlag(NodeFlag $flag): bool;
    /** @return list<SemanticNode> hijos directos (permiten BFS/DFS). */
    public function children(): array;
    public function sourceSpan(): SourceSpan;

    /** Tipo inferido (null hasta Pass 3 — Resolve+TypeInference). */
    public function inferredType(): ?DataType;
    public function withInferredType(DataType $t): static;

    /**
     * Doble dispatch. Cada implementación concreta llama a su homónimo en Visitor.
     *
     * Ej: ColumnReference::accept → $v->visitColumnReference($this);
     */
    public function accept(NodeVisitor $v): mixed;
}
