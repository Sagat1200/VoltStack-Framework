<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class BetweenPredicateNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly SemanticNode $operand,
        public readonly SemanticNode $lower,
        public readonly SemanticNode $upper,
        public readonly bool $negated = false,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::Between; }
    public function children(): array { return [$this->operand, $this->lower, $this->upper]; }
    public function accept(NodeVisitor $v): mixed { return $v->visitPredicate($this); }
}
