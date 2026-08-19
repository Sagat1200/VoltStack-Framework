<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class UpdateStatementNode implements SemanticNode
{
    use SemanticNodeTrait;

    /**
     * @param string $tableName
     * @param ?string $alias
     * @param list<UpdateAssignmentNode> $assignments
     * @param ?SemanticNode $where predicate
     * @param ?ReturningClauseNode $returning
     */
    public function __construct(
        public readonly string $tableName,
        public readonly ?string $alias,
        public readonly array $assignments,
        public readonly ?SemanticNode $where = null,
        public readonly ?ReturningClauseNode $returning = null,
        public readonly ?string $schema = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::UpdateStatement; }
    public function children(): array
    {
        $out = array_values($this->assignments);
        if ($this->where) $out[] = $this->where;
        if ($this->returning) $out[] = $this->returning;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitRoot($this); }
}
