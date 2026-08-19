<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * INSERT root node.
 */
final class InsertStatementNode implements SemanticNode
{
    use SemanticNodeTrait;

    /**
     * @param string $tableName
     * @param list<string> $targetColumns
     * @param SemanticNode $source  ValuesSourceNode | SelectStatementNode | SubquerySourceNode
     * @param ?UpsertClauseNode $onConflict
     * @param ?ReturningClauseNode $returning
     */
    public function __construct(
        public readonly string $tableName,
        public readonly array $targetColumns,
        public readonly SemanticNode $source,
        public readonly ?UpsertClauseNode $onConflict = null,
        public readonly ?ReturningClauseNode $returning = null,
        public readonly ?string $schema = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::InsertStatement; }
    public function children(): array
    {
        $out = [$this->source];
        if ($this->onConflict) $out[] = $this->onConflict;
        if ($this->returning) $out[] = $this->returning;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitRoot($this); }
}
