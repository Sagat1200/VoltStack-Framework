<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class DistinctModifierNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<SemanticNode>|null $onExpressions  DISTINCT ON (col1,col2...)  (PostgreSQL) */
    public function __construct(
        public readonly ?array $onExpressions = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::DistinctModifier; }
    public function children(): array { return array_values((array)$this->onExpressions); }
    public function accept(NodeVisitor $v): mixed { return $v->visitModifier($this); }
}
