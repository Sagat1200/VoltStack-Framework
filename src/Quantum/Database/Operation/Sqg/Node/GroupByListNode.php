<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class GroupByListNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<SemanticNode> $expressions */
    public function __construct(
        public readonly array $expressions,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::GroupByList; }
    public function children(): array { return array_values($this->expressions); }
    public function accept(NodeVisitor $v): mixed { return $v->visitAggregate($this); }
}
