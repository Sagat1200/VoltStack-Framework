<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\Enum\UnaryOperator;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class UnaryExpressionNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly UnaryOperator $op,
        public readonly SemanticNode $operand,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind
    {
        return $this->op === UnaryOperator::Not ? SemanticNodeKind::PredicateNot : SemanticNodeKind::UnaryExpression;
    }

    public function children(): array { return [$this->operand]; }
    public function accept(NodeVisitor $v): mixed
    {
        return $this->op === UnaryOperator::Not ? $v->visitPredicate($this) : $v->visitExpression($this);
    }
}
