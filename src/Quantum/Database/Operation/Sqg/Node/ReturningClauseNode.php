<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * RETURNING col1, col2, ... para INSERT/UPDATE/DELETE (PgSQL/SQLite/MariaDB-10.5+)
 */
final class ReturningClauseNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<SemanticNode> $items ColumnReferenceNode | AliasedProjectionNode | StarProjectionNode */
    public function __construct(
        public readonly array $items,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::ReturningClause; }
    public function children(): array { return array_values($this->items); }
    public function accept(NodeVisitor $v): mixed { return $v->visitMutation($this); }
}
