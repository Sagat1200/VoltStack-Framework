<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Dialect\Enum\OrderDirection;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\Enum\SortNulls;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class OrderByItemNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly SemanticNode $expression,
        public readonly OrderDirection $direction = OrderDirection::Asc,
        public readonly SortNulls $nulls = SortNulls::Default,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::OrderByItem; }
    public function children(): array { return [$this->expression]; }
    public function accept(NodeVisitor $v): mixed { return $v->visitModifier($this); }
}
