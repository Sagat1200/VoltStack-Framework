<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class ProjectionListNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<AliasedProjectionNode|StarProjectionNode|QualifiedStarProjectionNode|SemanticNode> $items */
    public function __construct(
        public readonly array $items,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::ProjectionList; }
    public function children(): array { return array_values($this->items); }
    public function accept(NodeVisitor $v): mixed { return $v->visitProjection($this); }
}
