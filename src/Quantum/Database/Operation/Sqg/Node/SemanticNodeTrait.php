<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Enum\NodeFlag;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;
use Quantum\Database\Operation\Sqg\SourceSpan;

/**
 * Trait base para todos los SemanticNode concretos (readonly classes V1).
 * Reduce boilerplate. Los nodos concretos solo definen sus props específicas,
 * list de children y familia accept().
 *
 * @property-read string $id
 * @property-read SemanticNodeKind $kind
 */
trait SemanticNodeTrait
{
    private string $__nodeId;
    private int $__flags = 0;
    private ?DataType $__inferredType = null;
    private SourceSpan $__span;

    /** Inicializa trait. Llamar desde constructor del nodo concreto, último paso. */
    protected function initNode(
        ?string $id = null,
        int $flags = 0,
        ?SourceSpan $span = null,
    ): void {
        $this->__nodeId = $id ?? ('n_' . bin2hex(random_bytes(7)));
        $this->__flags  = $flags;
        $this->__span   = $span ?? SourceSpan::none();
    }

    final public function id(): string { return $this->__nodeId; }
    abstract public function kind(): SemanticNodeKind;
    final public function flags(): int { return $this->__flags; }
    final public function hasFlag(NodeFlag $flag): bool { return ($this->__flags & $flag->value) !== 0; }
    final public function sourceSpan(): SourceSpan { return $this->__span; }
    final public function inferredType(): ?DataType { return $this->__inferredType; }

    final public function withInferredType(DataType $t): static
    {
        $clone = clone $this;
        $clone->__inferredType = $t;
        $clone->__flags |= NodeFlag::ResolvedType->value;
        return $clone;
    }

    /** @return list<SemanticNode>  */
    abstract public function children(): array;
    abstract public function accept(\Quantum\Database\Operation\Sqg\NodeVisitor $v): mixed;
}
