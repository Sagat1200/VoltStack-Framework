<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Dialect\Enum\JoinType;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class JoinNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly JoinType $type,
        public readonly SemanticNode $right,     // TableSourceNode|SubquerySourceNode
        public readonly ?SemanticNode $on = null, // predicate
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind
    {
        return match($this->type) {
            JoinType::Inner => SemanticNodeKind::InnerJoin,
            JoinType::Left  => SemanticNodeKind::LeftJoin,
            JoinType::Right => SemanticNodeKind::RightJoin,
            JoinType::Full  => SemanticNodeKind::FullJoin,
            JoinType::Cross => SemanticNodeKind::CrossJoin,
        };
    }

    public function children(): array { return array_filter([$this->right, $this->on]); }
    public function accept(NodeVisitor $v): mixed { return $v->visitJoin($this); }
}
