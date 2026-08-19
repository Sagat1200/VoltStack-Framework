<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class AggregateFunctionNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<SemanticNode> $args */
    public function __construct(
        public readonly AggregateFunctionKind $fn,
        public readonly array $args,
        public readonly bool $distinct = false,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id, $flags | \Quantum\Database\Operation\Sqg\Enum\NodeFlag::AggregatePresent->value, $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::AggregateFunction; }
    public function children(): array { return array_values($this->args); }
    public function accept(NodeVisitor $v): mixed { return $v->visitAggregate($this); }
}
