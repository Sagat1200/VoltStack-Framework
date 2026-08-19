<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class BinaryExpressionNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly BinaryOperator $op,
        public readonly SemanticNode $left,
        public readonly SemanticNode $right,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind
    {
        return match($this->op) {
            BinaryOperator::AndAlso => SemanticNodeKind::PredicateAnd,
            BinaryOperator::OrElse  => SemanticNodeKind::PredicateOr,
            BinaryOperator::Eq, BinaryOperator::NotEq,
            BinaryOperator::Lt, BinaryOperator::Lte,
            BinaryOperator::Gt, BinaryOperator::Gte,
            BinaryOperator::Like, BinaryOperator::ILike,
            BinaryOperator::SimilarTo => SemanticNodeKind::Comparison,
            default => SemanticNodeKind::BinaryExpression,
        };
    }

    public function children(): array { return [$this->left, $this->right]; }
    public function accept(NodeVisitor $v): mixed
    {
        $k = $this->kind();
        if (in_array($k->value, ['predicate_and','predicate_or','comparison','between','in_list','in_subquery','exists','is_null','is_distinct_from'], true)) {
            return $v->visitPredicate($this);
        }
        return $v->visitExpression($this);
    }
}
