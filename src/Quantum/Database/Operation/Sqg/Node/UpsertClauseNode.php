<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * ON CONFLICT / ON DUPLICATE KEY — abstracto: el dialect decide qué emitir.
 *
 * $strategy = 'do_nothing' | 'do_update' | 'ignore' | 'replace'
 *
 * $conflictTarget = list<string>  (keys for ON CONFLICT (a,b,c) — solo PgSQL-like; null para ON DUPLICATE KEY mysql-style)
 * $assignments = list<UpdateAssignmentNode>  para DO UPDATE SET ...
 */
final class UpsertClauseNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly string $strategy,
        public readonly ?array $conflictTarget = null,
        public readonly ?array $assignments = null,
        public readonly ?SemanticNode $where = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::UpsertClause; }
    public function children(): array
    {
        $out = [];
        foreach ((array)$this->assignments as $a) $out[] = $a;
        if ($this->where) $out[] = $this->where;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitMutation($this); }
}
