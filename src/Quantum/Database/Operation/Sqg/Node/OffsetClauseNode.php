<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class OffsetClauseNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly int $offset,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::OffsetClause; }
    public function children(): array { return []; }
    public function accept(NodeVisitor $v): mixed { return $v->visitModifier($this); }
}
