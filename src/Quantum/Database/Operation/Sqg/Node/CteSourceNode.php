<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class CteSourceNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly string $name,
        public readonly SemanticNode $subquery, // SelectStatementNode
        public readonly ?array $columnAliases = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags | \Quantum\Database\Operation\Sqg\Enum\NodeFlag::HasSubquery->value, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::CteSource; }
    public function children(): array { return [$this->subquery]; }
    public function accept(NodeVisitor $v): mixed { return $v->visitSource($this); }
}
