<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class DeleteStatementNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly string $tableName,
        public readonly ?string $alias,
        public readonly ?SemanticNode $where = null,
        public readonly ?ReturningClauseNode $returning = null,
        public readonly ?string $schema = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::DeleteStatement; }
    public function children(): array
    {
        $out = [];
        if ($this->where) $out[] = $this->where;
        if ($this->returning) $out[] = $this->returning;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitRoot($this); }
}
