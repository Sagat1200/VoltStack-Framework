<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class CteListNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<CteSourceNode> $ctes */
    public function __construct(
        public readonly array $ctes,
        public readonly bool $recursive = false,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::CteList; }
    public function children(): array { return array_values($this->ctes); }
    public function accept(NodeVisitor $v): mixed { return $v->visitSource($this); }
}
