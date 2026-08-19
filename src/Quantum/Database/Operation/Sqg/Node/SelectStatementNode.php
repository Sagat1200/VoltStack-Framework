<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * Node: SELECT root (no-read-only setters via withXxx).
 * V1 soporta las 8 piezas: CTE, DISTINCT, projections, from/sources, joins, where, groupBy/having, orderBy, limit/offset.
 */
final class SelectStatementNode implements SemanticNode
{
    use SemanticNodeTrait;

    /**
     * @param ?CteListNode $with
     * @param ?DistinctModifierNode $distinct
     * @param ProjectionListNode $projections
     * @param list<SemanticNode> $fromSources TableSource | SubquerySource | ValuesSource | CteSource
     * @param list<SemanticNode> $joins InnerJoin | LeftJoin | CrossJoin...
     * @param ?SemanticNode $where predicate
     * @param ?GroupByListNode $groupBy
     * @param ?HavingClauseNode $having
     * @param ?OrderByListNode $orderBy
     * @param ?LimitClauseNode $limit
     * @param ?OffsetClauseNode $offset
     */
    public function __construct(
        public readonly ?CteListNode $with = null,
        public readonly ?DistinctModifierNode $distinct = null,
        public readonly ?ProjectionListNode $projections = null,
        public readonly array $fromSources = [],
        public readonly array $joins = [],
        public readonly ?SemanticNode $where = null,
        public readonly ?GroupByListNode $groupBy = null,
        public readonly ?HavingClauseNode $having = null,
        public readonly ?OrderByListNode $orderBy = null,
        public readonly ?LimitClauseNode $limit = null,
        public readonly ?OffsetClauseNode $offset = null,
        ?string $id = null,
        int $flags = 0,
        ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id, $flags, $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::SelectStatement; }

    public function children(): array
    {
        $out = [];
        if ($this->with) $out[] = $this->with;
        if ($this->distinct) $out[] = $this->distinct;
        if ($this->projections) $out[] = $this->projections;
        foreach ($this->fromSources as $s) $out[] = $s;
        foreach ($this->joins as $j) $out[] = $j;
        if ($this->where) $out[] = $this->where;
        if ($this->groupBy) $out[] = $this->groupBy;
        if ($this->having) $out[] = $this->having;
        if ($this->orderBy) $out[] = $this->orderBy;
        if ($this->limit) $out[] = $this->limit;
        if ($this->offset) $out[] = $this->offset;
        return $out;
    }

    public function accept(NodeVisitor $v): mixed { return $v->visitRoot($this); }
}
