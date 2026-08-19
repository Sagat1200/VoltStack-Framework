<?php

declare(strict_types=1);

namespace Quantum\Database\Dialect\Emitter;

use Quantum\Database\Dialect\Value\CompiledSql;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Enum\UnaryOperator;
use Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\UpdateStatementNode;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;
use Quantum\Database\Operation\Sqg\Node\TableSourceNode;
use Quantum\Database\Operation\Sqg\Node\SubquerySourceNode;
use Quantum\Database\Operation\Sqg\Node\ValuesSourceNode;
use Quantum\Database\Operation\Sqg\Node\ColumnReferenceNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Operation\Sqg\Node\ParameterNode;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\UnaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode;
use Quantum\Database\Operation\Sqg\Node\StarProjectionNode;
use Quantum\Database\Operation\Sqg\Node\QualifiedStarProjectionNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
use Quantum\Database\Operation\Sqg\Node\OrderByListNode;
use Quantum\Database\Operation\Sqg\Node\OrderByItemNode;
use Quantum\Database\Operation\Sqg\Node\LimitClauseNode;
use Quantum\Database\Operation\Sqg\Node\OffsetClauseNode;
use Quantum\Database\Operation\Sqg\Node\GroupByListNode;
use Quantum\Database\Operation\Sqg\Node\HavingClauseNode;
use Quantum\Database\Operation\Sqg\Node\IsNullPredicateNode;
use Quantum\Database\Operation\Sqg\Node\BetweenPredicateNode;
use Quantum\Database\Operation\Sqg\Node\InListPredicateNode;
use Quantum\Database\Operation\Sqg\Node\InSubqueryPredicateNode;
use Quantum\Database\Operation\Sqg\Node\ExistsPredicateNode;
use Quantum\Database\Operation\Sqg\Node\IsDistinctFromNode;
use Quantum\Database\Operation\Sqg\Node\AggregateFunctionNode;
use Quantum\Database\Operation\Sqg\Node\FunctionCallNode;
use Quantum\Database\Operation\Sqg\Node\CastExpressionNode;
use Quantum\Database\Operation\Sqg\Node\CaseExpressionNode;
use Quantum\Database\Operation\Sqg\Node\SubqueryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\DistinctModifierNode;
use Quantum\Database\Operation\Sqg\Node\WindowFunctionNode;
use Quantum\Database\Operation\Sqg\Node\WindowSpecificationNode;
use Quantum\Database\Operation\Sqg\Node\UpdateAssignmentNode;
use Quantum\Database\Operation\Sqg\Node\ReturningClauseNode;
use Quantum\Database\Operation\Sqg\Node\UpsertClauseNode;
use Quantum\Database\Operation\Sqg\Node\CteListNode;

/**
 * Emite SQL + parámetros a partir de nodos SQG. Por dialecto solo cambian quoting/placeholders
 * y pequeñas variantes (IS [NOT] DISTINCT FROM / ILike / RETURNING / UPSERT syntax).
 */
class NodeSqlEmitter implements NodeVisitor
{
    /** @var list<mixed> */
    private array $params = [];
    private int $pIndex = 0;

    public function __construct(
        protected readonly DialectInterface $dialect,
    ) {}

    /**
     * @param SemanticNode $root statement root
     * @param list<mixed> $graphParameters ordered parameters (0-indexed) matching ParameterNode::$index
     * @return CompiledSql
     */
    public function emit(SemanticNode $root, array $graphParameters = []): CompiledSql
    {
        $this->params = [];
        $this->pIndex = 0;
        $sql = $this->node($root);
        return new CompiledSql(
            sql: $sql,
            params: $graphParameters,
            paramCount: count($graphParameters),
            fingerprint: CompiledSql::fingerprintFor($this->strip($sql)),
            quoteStyle: $this->dialect->quoteStyle(),
            paramStyle: $this->dialect->paramStyle(),
        );
    }

    private function strip(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        $s = (string)preg_replace("/'[^']*'/", "''", $s);
        $s = (string)preg_replace('/"[^"]*"/', '""', $s);
        $s = (string)preg_replace('/`[^`]*`/', '``', $s);
        $s = (string)preg_replace('/\b\d+(\.\d+)?\b/', '0', $s);
        return $s;
    }

    // ---------------- Family dispatch (accept → double dispatch via accept() calls visitor methods) ----------------
    public function enterNode(SemanticNode $n): void {}
    public function leaveNode(SemanticNode $n): void {}

    public function visitRoot($n): mixed
    {
        return $this->node($n);
    }
    public function visitSource($n): mixed
    {
        return $this->node($n);
    }
    public function visitJoin($n): mixed
    {
        return $this->node($n);
    }
    public function visitProjection($n): mixed
    {
        return $this->node($n);
    }
    public function visitPredicate($n): mixed
    {
        return $this->node($n);
    }
    public function visitExpression($n): mixed
    {
        return $this->node($n);
    }
    public function visitAggregate($n): mixed
    {
        return $this->node($n);
    }
    public function visitModifier($n): mixed
    {
        return $this->node($n);
    }
    public function visitMutation($n): mixed
    {
        return $this->node($n);
    }

    // ---------------- node() main dispatch ----------------

    private function node(mixed $n): string
    {
        return match (true) {
            $n instanceof SelectStatementNode => $this->emitSelect($n),
            $n instanceof InsertStatementNode => $this->emitInsert($n),
            $n instanceof UpdateStatementNode => $this->emitUpdate($n),
            $n instanceof DeleteStatementNode => $this->emitDelete($n),
            $n instanceof TableSourceNode => $this->emitTableSource($n),
            $n instanceof SubquerySourceNode => $this->emitSubquerySource($n),
            $n instanceof ValuesSourceNode => $this->emitValuesSource($n),
            $n instanceof ColumnReferenceNode => $this->emitColumnRef($n),
            $n instanceof LiteralNode => $this->emitLiteral($n),
            $n instanceof ParameterNode => $this->emitParameter($n),
            $n instanceof BinaryExpressionNode => $this->emitBinary($n),
            $n instanceof UnaryExpressionNode => $this->emitUnary($n),
            $n instanceof ProjectionListNode => $this->emitProjectionList($n),
            $n instanceof AliasedProjectionNode => $this->emitAliasedProj($n),
            $n instanceof StarProjectionNode => '*',
            $n instanceof QualifiedStarProjectionNode => $this->dialect->quoteIdentifier($n->tableAlias) . '.*',
            $n instanceof JoinNode => $this->emitJoin($n),
            $n instanceof OrderByListNode => $this->emitOrderList($n),
            $n instanceof OrderByItemNode => $this->emitOrderItem($n),
            $n instanceof LimitClauseNode => 'LIMIT ' . $this->intLiteral($n->limit),
            $n instanceof OffsetClauseNode => 'OFFSET ' . $this->intLiteral($n->offset),
            $n instanceof GroupByListNode => $this->emitGroupBy($n),
            $n instanceof HavingClauseNode => 'HAVING ' . $this->node($n->predicate),
            $n instanceof IsNullPredicateNode => $this->node($n->operand) . ($n->negated ? ' IS NOT NULL' : ' IS NULL'),
            $n instanceof BetweenPredicateNode => $this->node($n->operand) . ($n->negated ? ' NOT BETWEEN ' : ' BETWEEN ') . $this->node($n->lower) . ' AND ' . $this->node($n->upper),
            $n instanceof InListPredicateNode => $this->node($n->operand) . ($n->negated ? ' NOT IN (' : ' IN (') . implode(', ', array_map($this->node(...), $n->list)) . ')',
            $n instanceof InSubqueryPredicateNode => $this->node($n->operand) . ($n->negated ? ' NOT IN (' : ' IN (') . $this->node($n->subquery) . ')',
            $n instanceof ExistsPredicateNode => ($n->negated ? 'NOT EXISTS (' : 'EXISTS (') . $this->node($n->subquery) . ')',
            $n instanceof IsDistinctFromNode => $this->emitIsDistinctFrom($n),
            $n instanceof AggregateFunctionNode => $this->emitAggregate($n),
            $n instanceof FunctionCallNode => $this->emitFunctionCall($n),
            $n instanceof CastExpressionNode => $this->emitCast($n),
            $n instanceof CaseExpressionNode => $this->emitCase($n),
            $n instanceof SubqueryExpressionNode => '(' . $this->node($n->subquery) . ')',
            $n instanceof DistinctModifierNode => $this->emitDistinctModifier($n),
            $n instanceof WindowFunctionNode => $this->emitWindowFunction($n),
            $n instanceof WindowSpecificationNode => $this->emitWindowSpec($n),
            $n instanceof UpdateAssignmentNode => $this->dialect->quoteIdentifier($n->column) . ' = ' . $this->node($n->value),
            $n instanceof ReturningClauseNode => 'RETURNING ' . implode(', ', array_map($this->node(...), $n->items)),
            $n instanceof UpsertClauseNode => $this->emitUpsert($n),
            $n instanceof CteListNode => $this->emitCteList($n),
            default => throw new \RuntimeException('Unhandled SQG node: ' . ($n::class ?? 'null')),
        };
    }

    protected function emitTableSource(TableSourceNode $n): string
    {
        $t = $n->schema !== null ? $this->dialect->quoteIdentifier($n->schema) . '.' . $this->dialect->quoteIdentifier($n->tableName) : $this->dialect->quoteIdentifier($n->tableName);
        if ($n->alias !== null && $n->alias !== $n->tableName) {
            return "{$t} AS " . $this->dialect->quoteIdentifier($n->alias);
        }
        return $t;
    }

    protected function emitSubquerySource(SubquerySourceNode $n): string
    {
        return '(' . $this->node($n->subquery) . ') AS ' . $this->dialect->quoteIdentifier($n->alias);
    }

    protected function emitValuesSource(ValuesSourceNode $n): string
    {
        $cc = $n->columnCount;
        $rc = $n->rowCount;
        $flat = $n->flattened;
        $rows = [];
        for ($r = 0; $r < $rc; $r++) {
            $row = [];
            for ($c = 0; $c < $cc; $c++) {
                $el = $flat[$r * $cc + $c] ?? new \Quantum\Database\Operation\Sqg\Node\LiteralNode(null);
                $row[] = $this->node($el);
            }
            $rows[] = '(' . implode(', ', $row) . ')';
        }
        $s = 'VALUES ' . implode(', ', $rows);
        if ($n->alias !== null) {
            $alias = $this->dialect->quoteIdentifier($n->alias);
            $cols = $n->columnAliases !== null ? '(' . implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $n->columnAliases)) . ')' : '';
            $s = "($s) AS {$alias}{$cols}";
        }
        return $s;
    }

    protected function emitColumnRef(ColumnReferenceNode $n): string
    {
        $col = $this->dialect->quoteIdentifier($n->column);
        if ($n->tableAlias !== null) {
            return $this->dialect->quoteIdentifier($n->tableAlias) . '.' . $col;
        }
        return $col;
    }

    protected function emitLiteral(LiteralNode $n): string
    {
        $v = $n->value;
        if ($v === null) return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return (string)$v;
        // Safe literal (but prefer ParameterNode always). Escapa ' → ''.
        return "'" . str_replace("'", "''", (string)$v) . "'";
    }

    protected function intLiteral(int $v): string
    {
        return (string)$v;
    }

    protected function emitParameter(ParameterNode $n): string
    {
        // Placeholder positional: usamos el orden de emisión ($this->pIndex) que coincide
        // con el orden ParameterNode::$index si el builder fue correcto.
        $placeholder = $this->dialect->parameterPlaceholder($this->pIndex++);
        return $placeholder;
    }

    protected function emitBinary(BinaryExpressionNode $n): string
    {
        $opStr = match ($n->op) {
            BinaryOperator::AndAlso => 'AND',
            BinaryOperator::OrElse  => 'OR',
            BinaryOperator::Eq  => '=',
            BinaryOperator::NotEq => '<>',
            BinaryOperator::Lt  => '<',
            BinaryOperator::Lte => '<=',
            BinaryOperator::Gt  => '>',
            BinaryOperator::Gte => '>=',
            BinaryOperator::Like => 'LIKE',
            BinaryOperator::ILike => $this->emitILikeOperator(),
            BinaryOperator::SimilarTo => 'SIMILAR TO',
            BinaryOperator::Plus => '+',
            BinaryOperator::Minus => '-',
            BinaryOperator::Star => '*',
            BinaryOperator::Slash => '/',
            BinaryOperator::Percent => '%',
            BinaryOperator::Concat => $this->emitConcatOperator(),
            BinaryOperator::BitAnd => '&',
            BinaryOperator::BitOr => '|',
            default => '/*op:' . $n->op->name . '*/',
        };
        $wrap = in_array($n->op, [BinaryOperator::AndAlso, BinaryOperator::OrElse, BinaryOperator::Plus, BinaryOperator::Star], true);
        $l = $this->node($n->left);
        $r = $this->node($n->right);
        return ($wrap ? '(' : '') . "{$l} {$opStr} {$r}" . ($wrap ? ')' : '');
    }

    protected function emitUnary(UnaryExpressionNode $n): string
    {
        $op = match ($n->op) {
            UnaryOperator::Not => 'NOT ',
            UnaryOperator::Neg => '-',
            UnaryOperator::Pos => '+',
            UnaryOperator::BitNot => '~',
            default => '/*u:' . $n->op->name . '*/',
        };
        return $op . $this->node($n->operand);
    }

    protected function emitProjectionList(ProjectionListNode $n): string
    {
        return implode(', ', array_map($this->node(...), $n->items));
    }

    protected function emitAliasedProj(AliasedProjectionNode $n): string
    {
        return $this->node($n->expression) . ' AS ' . $this->dialect->quoteIdentifier($n->alias);
    }

    protected function emitJoin(JoinNode $n): string
    {
        $keyword = match ($n->joinType) {
            \Quantum\Database\Dialect\Enum\JoinType::Inner => 'INNER JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::Left => 'LEFT JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::Right => 'RIGHT JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::Full => 'FULL JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::Cross => 'CROSS JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::LeftOuter => 'LEFT OUTER JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::RightOuter => 'RIGHT OUTER JOIN',
            \Quantum\Database\Dialect\Enum\JoinType::FullOuter => 'FULL OUTER JOIN',
            default => 'JOIN',
        };
        $s = "{$keyword} " . $this->node($n->right);
        if ($n->joinType !== \Quantum\Database\Dialect\Enum\JoinType::Cross && $n->onPredicate !== null) {
            $s .= ' ON ' . $this->node($n->onPredicate);
        }
        return $s;
    }

    protected function emitOrderList(OrderByListNode $n): string
    {
        return 'ORDER BY ' . implode(', ', array_map($this->node(...), $n->items));
    }

    protected function emitOrderItem(OrderByItemNode $n): string
    {
        $dir = $n->direction === \Quantum\Database\Dialect\Enum\OrderDirection::Asc ? 'ASC' : 'DESC';
        $s = $this->node($n->expression) . ' ' . $dir;
        if ($n->nulls !== null) {
            $s .= ' ' . match ($n->nulls) {
                \Quantum\Database\Operation\Sqg\Enum\SortNulls::First => 'NULLS FIRST',
                \Quantum\Database\Operation\Sqg\Enum\SortNulls::Last => 'NULLS LAST',
                default => '',
            };
        }
        return $s;
    }

    protected function emitGroupBy(GroupByListNode $n): string
    {
        return 'GROUP BY ' . implode(', ', array_map($this->node(...), $n->expressions));
    }

    protected function emitSelect(SelectStatementNode $n): string
    {
        $parts = ['SELECT'];
        if ($n->distinct !== null) {
            $parts[] = $this->node($n->distinct);
        }
        $parts[] = $n->projections ? $this->node($n->projections) : '*';
        if (count($n->fromSources) > 0) {
            $parts[] = 'FROM ' . implode(', ', array_map($this->node(...), $n->fromSources));
        }
        if (count($n->joins) > 0) {
            foreach ($n->joins as $j) $parts[] = $this->node($j);
        }
        if ($n->where !== null) {
            $parts[] = 'WHERE ' . $this->node($n->where);
        }
        if ($n->groupBy !== null) {
            $parts[] = $this->node($n->groupBy);
        }
        if ($n->having !== null) {
            $parts[] = $this->node($n->having);
        }
        if ($n->orderBy !== null) {
            $parts[] = $this->node($n->orderBy);
        }
        if ($n->limit !== null) {
            $parts[] = $this->node($n->limit);
        }
        if ($n->offset !== null) {
            $parts[] = $this->node($n->offset);
        }
        if ($n->with !== null) {
            $parts[0] = $this->node($n->with) . ' SELECT';
        }
        return implode(' ', $parts);
    }

    protected function emitInsert(InsertStatementNode $n): string
    {
        $table = $n->schema !== null
            ? $this->dialect->quoteIdentifier($n->schema) . '.' . $this->dialect->quoteIdentifier($n->tableName)
            : $this->dialect->quoteIdentifier($n->tableName);
        $cols = implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $n->targetColumns));
        $sql = "INSERT INTO {$table} ({$cols}) " . $this->node($n->source);
        if ($n->onConflict !== null) {
            $sql .= ' ' . $this->node($n->onConflict);
        }
        if ($n->returning !== null) {
            $sql .= ' ' . $this->node($n->returning);
        }
        return $sql;
    }

    protected function emitUpdate(UpdateStatementNode $n): string
    {
        $table = $n->schema !== null
            ? $this->dialect->quoteIdentifier($n->schema) . '.' . $this->dialect->quoteIdentifier($n->tableName)
            : $this->dialect->quoteIdentifier($n->tableName);
        if ($n->alias !== null) {
            $table .= ' AS ' . $this->dialect->quoteIdentifier($n->alias);
        }
        $sql = "UPDATE {$table} SET " . implode(', ', array_map($this->node(...), $n->assignments));
        if ($n->where !== null) $sql .= ' WHERE ' . $this->node($n->where);
        if ($n->returning !== null) $sql .= ' ' . $this->node($n->returning);
        return $sql;
    }

    protected function emitDelete(DeleteStatementNode $n): string
    {
        $table = $n->schema !== null
            ? $this->dialect->quoteIdentifier($n->schema) . '.' . $this->dialect->quoteIdentifier($n->tableName)
            : $this->dialect->quoteIdentifier($n->tableName);
        if ($n->alias !== null) {
            $table .= ' AS ' . $this->dialect->quoteIdentifier($n->alias);
        }
        $sql = "DELETE FROM {$table}";
        if ($n->where !== null) $sql .= ' WHERE ' . $this->node($n->where);
        if ($n->returning !== null) $sql .= ' ' . $this->node($n->returning);
        return $sql;
    }

    protected function emitIsDistinctFrom(IsDistinctFromNode $n): string
    {
        $kw = $n->negated ? 'IS NOT DISTINCT FROM' : 'IS DISTINCT FROM';
        return $this->node($n->left) . " {$kw} " . $this->node($n->right);
    }

    protected function emitAggregate(AggregateFunctionNode $n): string
    {
        $fn = match ($n->fn) {
            AggregateFunctionKind::Count => 'COUNT',
            AggregateFunctionKind::CountStar => 'COUNT',
            AggregateFunctionKind::Sum => 'SUM',
            AggregateFunctionKind::Avg => 'AVG',
            AggregateFunctionKind::Min => 'MIN',
            AggregateFunctionKind::Max => 'MAX',
            AggregateFunctionKind::StringAgg => 'STRING_AGG',
            AggregateFunctionKind::ArrayAgg => 'ARRAY_AGG',
            AggregateFunctionKind::GroupConcat => $this->emitGroupConcatFnName(),
            default => $n->fn->name,
        };
        $distinct = $n->distinct ? 'DISTINCT ' : '';
        $args = ($n->args === [] && in_array($n->fn, [AggregateFunctionKind::Count, AggregateFunctionKind::CountStar], true))
            ? ['*']
            : array_map($this->node(...), $n->args);
        return "{$fn}({$distinct}" . implode(', ', $args) . ')';
    }

    protected function emitFunctionCall(FunctionCallNode $n): string
    {
        return $n->functionName . '(' . implode(', ', array_map($this->node(...), $n->args)) . ')';
    }

    protected function emitCast(CastExpressionNode $n): string
    {
        $sqlType = $this->mapDataType($n->targetType);
        return 'CAST(' . $this->node($n->operand) . ' AS ' . $sqlType . ')';
    }

    protected function mapDataType(\Quantum\Database\Operation\Sqg\Enum\DataType $t): string
    {
        return match ($t) {
            \Quantum\Database\Operation\Sqg\Enum\DataType::Utf8Text,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Varchar => 'VARCHAR',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Char => 'CHAR',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Boolean => 'BOOLEAN',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Int8,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Int16,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Int32 => 'INTEGER',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Int64,
            \Quantum\Database\Operation\Sqg\Enum\DataType::BigInt => 'BIGINT',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Float32 => 'REAL',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Float64 => 'DOUBLE PRECISION',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Decimal,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Numeric => 'NUMERIC',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Date => 'DATE',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Time => 'TIME',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Timestamp => 'TIMESTAMP',
            \Quantum\Database\Operation\Sqg\Enum\DataType::TimestampTz => 'TIMESTAMP WITH TIME ZONE',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Interval => 'INTERVAL',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Uuid => 'UUID',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Json => 'JSON',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Jsonb => 'JSONB',
            \Quantum\Database\Operation\Sqg\Enum\DataType::Blob,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Binary,
            \Quantum\Database\Operation\Sqg\Enum\DataType::Varbinary => 'BLOB',
            \Quantum\Database\Operation\Sqg\Enum\DataType::ArrayT => 'ARRAY',
            default => 'TEXT',
        };
    }

    protected function emitCase(CaseExpressionNode $n): string
    {
        $parts = ['CASE'];
        if ($n->operand !== null) $parts[] = $this->node($n->operand);
        foreach ($n->whenPairs as [$when, $then]) {
            $parts[] = 'WHEN ' . $this->node($when) . ' THEN ' . $this->node($then);
        }
        if ($n->else !== null) $parts[] = 'ELSE ' . $this->node($n->else);
        $parts[] = 'END';
        return implode(' ', $parts);
    }

    protected function emitDistinctModifier(DistinctModifierNode $n): string
    {
        if ($n->onExpressions !== null && count($n->onExpressions) > 0) {
            return 'DISTINCT ON (' . implode(', ', array_map($this->node(...), $n->onExpressions)) . ')';
        }
        return 'DISTINCT';
    }

    protected function emitWindowFunction(WindowFunctionNode $n): string
    {
        $args = implode(', ', array_map($this->node(...), $n->args));
        $winSql = '';
        if ($n->windowRef !== null) $winSql = $this->dialect->quoteIdentifier($n->windowRef);
        elseif ($n->window !== null) $winSql = $this->node($n->window);
        return "{$n->functionName}({$args}) OVER ({$winSql})";
    }

    protected function emitWindowSpec(WindowSpecificationNode $n): string
    {
        $parts = [];
        if (count($n->partitionBy) > 0) $parts[] = 'PARTITION BY ' . implode(', ', array_map($this->node(...), $n->partitionBy));
        if ($n->orderBy !== null) $parts[] = $this->node($n->orderBy);
        if ($n->frameClause !== null) $parts[] = $n->frameClause;
        return implode(' ', $parts);
    }

    protected function emitUpsert(UpsertClauseNode $n): string
    {
        return match ($n->strategy) {
            'do_nothing' => $this->upsertDoNothing($n),
            'do_update' => $this->upsertDoUpdate($n),
            'ignore' => 'IGNORE',
            'replace' => 'REPLACE',
            default => '/* upsert unknown */',
        };
    }

    protected function upsertDoNothing(UpsertClauseNode $n): string
    {
        $target = $n->conflictTarget !== null
            ? '(' . implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $n->conflictTarget)) . ')'
            : '';
        return "ON CONFLICT {$target} DO NOTHING";
    }

    protected function upsertDoUpdate(UpsertClauseNode $n): string
    {
        $target = $n->conflictTarget !== null
            ? '(' . implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $n->conflictTarget)) . ')'
            : '';
        $set = $n->assignments !== null ? implode(', ', array_map($this->node(...), $n->assignments)) : '';
        $where = $n->where !== null ? ' WHERE ' . $this->node($n->where) : '';
        return "ON CONFLICT {$target} DO UPDATE SET {$set}{$where}";
    }

    protected function emitCteList(CteListNode $n): string
    {
        $parts = [];
        foreach ($n->ctes as $cte) {
            if ($cte instanceof \Quantum\Database\Operation\Sqg\Node\CteSourceNode) {
                $cols = $cte->columnAliases !== null
                    ? '(' . implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $cte->columnAliases)) . ')'
                    : '';
                $parts[] = $this->dialect->quoteIdentifier($cte->name) . "{$cols} AS (" . $this->node($cte->subquery) . ')';
            }
        }
        $r = ($n->recursive ? 'WITH RECURSIVE ' : 'WITH ') . implode(', ', $parts);
        return $r;
    }

    protected function emitILikeOperator(): string
    {
        return 'ILIKE';
    }
    protected function emitConcatOperator(): string
    {
        return '||';
    }
    protected function emitGroupConcatFnName(): string
    {
        return 'STRING_AGG';
    }
}