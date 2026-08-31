<?php

declare(strict_types=1);

namespace Quantum\Database\Query;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Enum\ParamType;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Dialect\Enum\OrderDirection;
use Quantum\Database\Dialect\Enum\JoinType as DialectJoinType;
use Quantum\Database\Dialect\Support\AbstractDialect;
use Quantum\Database\Dialect\Support\SqliteDialect;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\SqgOperation;
use Quantum\Database\Operation\Sqg\Enum\AggregateFunctionKind;
use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Enum\SortNulls;
use Quantum\Database\Operation\Sqg\Enum\UnaryOperator;
use Quantum\Database\Operation\Sqg\GraphCertification;
use Quantum\Database\Operation\Sqg\Node\AggregateFunctionNode;
use Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\BetweenPredicateNode;
use Quantum\Database\Operation\Sqg\Node\ColumnReferenceNode;
use Quantum\Database\Operation\Sqg\Node\FunctionCallNode;
use Quantum\Database\Operation\Sqg\Node\InListPredicateNode;
use Quantum\Database\Operation\Sqg\Node\IsNullPredicateNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
use Quantum\Database\Operation\Sqg\Node\LimitClauseNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Operation\Sqg\Node\OffsetClauseNode;
use Quantum\Database\Operation\Sqg\Node\OrderByItemNode;
use Quantum\Database\Operation\Sqg\Node\OrderByListNode;
use Quantum\Database\Operation\Sqg\Node\ParameterNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\QualifiedStarProjectionNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\StarProjectionNode;
use Quantum\Database\Operation\Sqg\Node\TableSourceNode;
use Quantum\Database\Operation\Sqg\Node\UnaryExpressionNode;
use Quantum\Database\Operation\Sqg\SemanticNode;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Operation\DatabaseDiagnosticSnapshot;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\Pipeline\DefaultQueryOptimizer;
use Quantum\Database\Operation\Pipeline\NoOpQueryPlanner;
use Quantum\Database\Operation\Pipeline\QueryOptimizationInput;
use Quantum\Database\Query\Enum\JoinType;
use Quantum\Database\Query\Enum\Order;
use Quantum\Database\Query\Expression\CompositeExpression;
use Quantum\Database\Query\Expression\ExpressionBuilder;

/**
 * SelectQueryBuilder (DBAL raw, fluent inmutable).
 *
 * API fluent tipo Doctrine DBAL pero internamente NO construye SQL strings;
 * construye un SemanticQueryGraph node-typed y validate-pipeline-certified.
 *
 * Invariante QB-001: TODOS los métodos write devuelven clone(); $this original
 * nunca se modifica.
 *
 * @api
 */
final class SelectQueryBuilder implements \Stringable
{
    // =============== STATE ======================================================
    /** @var list<array{expr:string, alias:?string, raw:bool}> */
    private array $selectStack = [];
    private bool $distinctFlag = false;

    /** @var list<array{table:string, alias:string}> */
    private array $fromStack = [];

    /** @var list<array{type:JoinType, fromAlias:string, join:string, alias:?string, cond:?string}> */
    private array $joinStack = [];

    /** @var list<string|CompositeExpression> */
    private array $whereStack = [];

    /** @var list<string> */
    private array $groupByStack = [];

    /** @var list<string|CompositeExpression> */
    private array $havingStack = [];

    /** @var list<array{expr:string, dir:Order}> */
    private array $orderByStack = [];

    private ?int $limitVal = null;
    private ?int $offsetVal = null;

    private ParameterBag $params;

    // =============== CONSTRUCT ==================================================
    public function __construct(
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?DatabaseContext     $context = null,
        private readonly ?DatabaseOperationRuntime $runtime = null,
    ) {
        $this->params = new ParameterBag();
    }

    private ?DatabaseOperationPlan $lastOperationPlan = null;
    private ?DatabaseDiagnosticSnapshot $lastDiagnostic = null;

    public function __clone()
    {
        $this->params = clone $this->params;
    }

    // =============== FLUENT WRITE (todos retorna clone) ========================

    /**
     * @param string|list<string> $selects
     */
    public function select(string|array $selects, ?string $alias = null): self
    {
        $clone = clone $this;
        $clone->selectStack = [];
        $clone->addSelectInt($selects, $alias);
        return $clone;
    }

    /**
     * @param string|list<string> $selects
     */
    public function addSelect(string|array $selects, ?string $alias = null): self
    {
        $clone = clone $this;
        $clone->addSelectInt($selects, $alias);
        return $clone;
    }

    public function distinct(bool $distinct = true): self
    {
        $clone = clone $this;
        $clone->distinctFlag = $distinct;
        return $clone;
    }

    public function from(string $table, string $alias): self
    {
        $clone = clone $this;
        $clone->fromStack[] = ['table' => $table, 'alias' => $alias];
        return $clone;
    }

    public function join(string $fromAlias, string $join, string $alias, ?string $cond = null, JoinType $type = JoinType::Inner): self
    {
        $clone = clone $this;
        $clone->joinStack[] = ['type' => $type, 'fromAlias' => $fromAlias, 'join' => $join, 'alias' => $alias, 'cond' => $cond];
        return $clone;
    }

    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $cond = null): self
    {
        return $this->join($fromAlias, $join, $alias, $cond, JoinType::Inner);
    }

    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $cond = null): self
    {
        return $this->join($fromAlias, $join, $alias, $cond, JoinType::Left);
    }

    public function rightJoin(string $fromAlias, string $join, string $alias, ?string $cond = null): self
    {
        return $this->join($fromAlias, $join, $alias, $cond, JoinType::Right);
    }

    public function crossJoin(string $fromAlias, string $join, string $alias): self
    {
        return $this->join($fromAlias, $join, $alias, null, JoinType::Cross);
    }

    public function where(string|CompositeExpression $expr): self
    {
        $clone = clone $this;
        $clone->whereStack = [$expr];
        return $clone;
    }

    public function andWhere(string|CompositeExpression $expr): self
    {
        $clone = clone $this;
        $clone->whereStack[] = $expr;
        return $clone;
    }

    public function orWhere(string|CompositeExpression $expr): self
    {
        // orWhere a nivel top-level: wrappear todo actual stack + nuevo en OR composite
        $clone = clone $this;
        $old = $clone->whereStack;
        if ($old === []) {
            $clone->whereStack = [$expr];
        } else {
            $and = count($old) === 1 ? $old[0] : new CompositeExpression(CompositeExpression::TYPE_AND, $old);
            $clone->whereStack = [new CompositeExpression(CompositeExpression::TYPE_OR, [$and, $expr])];
        }
        return $clone;
    }

    /**
     * @param string|list<string> $expr
     */
    public function groupBy(string|array $expr): self
    {
        $clone = clone $this;
        $clone->groupByStack = is_string($expr) ? [$expr] : $expr;
        return $clone;
    }

    /**
     * @param string|list<string> $expr
     */
    public function addGroupBy(string|array $expr): self
    {
        $clone = clone $this;
        if (is_string($expr)) {
            $clone->groupByStack[] = $expr;
        } else {
            array_push($clone->groupByStack, ...$expr);
        }
        return $clone;
    }

    public function having(string|CompositeExpression $expr): self
    {
        $clone = clone $this;
        $clone->havingStack = [$expr];
        return $clone;
    }

    public function andHaving(string|CompositeExpression $expr): self
    {
        $clone = clone $this;
        $clone->havingStack[] = $expr;
        return $clone;
    }

    public function orHaving(string|CompositeExpression $expr): self
    {
        $clone = clone $this;
        $old = $clone->havingStack;
        if ($old === []) {
            $clone->havingStack = [$expr];
        } else {
            $and = count($old) === 1 ? $old[0] : new CompositeExpression(CompositeExpression::TYPE_AND, $old);
            $clone->havingStack = [new CompositeExpression(CompositeExpression::TYPE_OR, [$and, $expr])];
        }
        return $clone;
    }

    public function orderBy(string $expr, Order $direction = Order::Asc): self
    {
        $clone = clone $this;
        $clone->orderByStack = [['expr' => $expr, 'dir' => $direction]];
        return $clone;
    }

    public function addOrderBy(string $expr, Order $direction = Order::Asc): self
    {
        $clone = clone $this;
        $clone->orderByStack[] = ['expr' => $expr, 'dir' => $direction];
        return $clone;
    }

    public function setMaxResults(?int $limit): self
    {
        if ($limit !== null && !is_int($limit)) {
            throw new \InvalidArgumentException('setMaxResults espera ?int');
        }
        $clone = clone $this;
        $clone->limitVal = $limit;
        return $clone;
    }

    public function setFirstResult(?int $offset): self
    {
        if ($offset !== null && !is_int($offset)) {
            throw new \InvalidArgumentException('setFirstResult espera ?int');
        }
        $clone = clone $this;
        $clone->offsetVal = $offset;
        return $clone;
    }

    public function setParameter(string $name, mixed $value, ?ParamType $type = null): self
    {
        $clone = clone $this;
        $clone->params->set($name, $value, $type);
        return $clone;
    }

    /**
     * @param array<string,mixed>|ParameterBag $parameters
     */
    public function setParameters(array|ParameterBag $parameters): self
    {
        $clone = clone $this;
        $clone->params->merge($parameters);
        return $clone;
    }

    /**
     * @return array<string,array{value:mixed,type:?ParamType}>
     */
    public function getParameters(): array
    {
        return $this->params->all();
    }

    // =============== BUILDER COMPUESTO =========================================

    public function expr(): ExpressionBuilder
    {
        return new ExpressionBuilder();
    }

    // =============== OUTPUTS ====================================================

    /**
     * Traduce estado QB → SQG node-typed → validate 5-passes → return certified.
     */
    public function getSQG(): SemanticQueryGraph
    {
        return $this->translateStateToSqg()['graph'];
    }

    /**
     * Devuelve SQL compilado con el dialecto por defecto (SQLite quoteStyle si
     * no hay connection, o el detectado).
     */
    public function getSQL(): string
    {
        ['graph' => $graph, 'dialect' => $dialect, 'caps' => $caps] = $this->translateStateToSqg();
        $op = $this->buildSqgOperation($graph, $caps);
        $compiled = $dialect->compile($op, $caps);
        return $compiled->sql;
    }

    /**
     * @return list<mixed> ordered positional values coincidiendo con ParameterNode index.
     */
    public function getPositionalParameters(): array
    {
        return $this->translateStateToSqg()['positional'];
    }

    public function executeQuery(): QueryResult
    {
        if ($this->connection === null) {
            throw new \RuntimeException('SelectQueryBuilder no tiene Connection: no puede executeQuery. Construya con $connection pasada al constructor o use getSQG() manual.');
        }
        ['graph' => $graph, 'dialect' => $dialect, 'caps' => $caps] = $this->translateStateToSqg();
        $op = $this->buildSqgOperation($graph, $caps);
        $compiled = $dialect->compile($op, $caps);

        if ($this->runtime === null || $this->context === null) {
            $this->lastOperationPlan = null;
            $this->lastDiagnostic = null;
            return $this->connection->executeQuery($compiled->sql, $compiled->params);
        }

        $context = $this->context->withConnection($this->connection);
        $policy = $this->runtimePolicyFromContext($context);
        $plan = $this->runtime->plan(
            operation: new \Quantum\Database\Operation\RawOperation(
                kind: OperationKind::RawQuery,
                sql: $compiled->sql,
                params: $compiled->params,
                comment: $this->connection->getDriverInfo()->databaseName !== ''
                    ? $this->connection->getDriverInfo()->databaseName
                    : 'default',
            ),
            context: $context,
            policy: $policy,
        );
        $result = $this->runtime->execute($plan, $context);
        $this->lastOperationPlan = $plan;
        $this->lastDiagnostic = $result->debug['diagnostic'] ?? null;

        if (!$result->queryResult instanceof QueryResult) {
            throw new \RuntimeException('Runtime SQG no devolvió QueryResult para una consulta SELECT.');
        }

        return $result->queryResult;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fetchAllAssociative(): array
    {
        return $this->executeQuery()->fetchAllAssoc();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetchAssociative(): ?array
    {
        $rows = $this->fetchAllAssociative();
        return $rows === [] ? null : $rows[0];
    }

    public function fetchOne(): mixed
    {
        $row = $this->fetchAssociative();
        if ($row === null || $row === []) {
            return null;
        }
        return $row[array_key_first($row)];
    }

    public function getLastOperationPlan(): ?DatabaseOperationPlan
    {
        return $this->lastOperationPlan;
    }

    public function getLastDiagnostic(): ?DatabaseDiagnosticSnapshot
    {
        return $this->lastDiagnostic;
    }

    /**
     * __toString = SQL-ish con named params :xxx para logs.
     */
    public function __toString(): string
    {
        return $this->buildNamedParamSqlLog();
    }

    // =============== HELPERS INTERNOS WRITE =====================================

    /**
     * @param string|list<string> $selects
     */
    private function addSelectInt(string|array $selects, ?string $alias): void
    {
        $items = is_string($selects) ? [$selects] : $selects;
        foreach ($items as $idx => $raw) {
            $a = ($idx === 0) ? $alias : null;
            $this->selectStack[] = ['expr' => $raw, 'alias' => $a, 'raw' => true];
        }
    }

    // =============== CORE: STATE → SQG + POSITIONAL PARAMS =====================

    /**
     * @return array{graph:SemanticQueryGraph, positional:list<mixed>, dialect:AbstractDialect, caps:DatabaseCapabilitySet}
     */
    private function translateStateToSqg(): array
    {
        $ctx = new SqgTranslatorContext();

        // 1. FROM sources
        $sources = [];
        $fromAliasToSourceId = [];
        foreach ($this->fromStack as $f) {
            $node = new TableSourceNode(tableName: $f['table'], alias: $f['alias']);
            $fromAliasToSourceId[$f['alias']] = $node->id();
            $sources[] = $node;
        }
        if ($sources === []) {
            throw new \RuntimeException('SelectQueryBuilder requiere FROM (puede usar tabla dual o tabla virtual según dialect).');
        }

        // 2. JOIN nodes
        $joins = [];
        foreach ($this->joinStack as $j) {
            $jt = match ($j['type']) {
                JoinType::Inner       => DialectJoinType::Inner,
                JoinType::Left        => DialectJoinType::Left,
                JoinType::Right       => DialectJoinType::Right,
                JoinType::FullOuter   => DialectJoinType::Full,
                JoinType::Cross       => DialectJoinType::Cross,
                JoinType::LeftLateral => DialectJoinType::Inner, // V1: no lateral, fallback inner
            };
            $target = new TableSourceNode(tableName: $j['join'], alias: $j['alias']);
            $fromAliasToSourceId[$j['alias']] = $target->id();
            $condNode = null;
            if ($j['cond'] !== null) {
                $condNode = $this->parseExpression($j['cond'], $ctx, 'boolean');
            }
            $joins[] = new JoinNode(
                type: $jt,
                source: $target,
                leftSourceId: $fromAliasToSourceId[$j['fromAlias']] ?? null,
                condition: $condNode,
            );
        }

        // 3. PROJECTIONS
        $projNodes = [];
        if ($this->selectStack === []) {
            $projNodes[] = new StarProjectionNode();
        } else {
            foreach ($this->selectStack as $item) {
                [$node, $parsedAlias] = $this->parseProjection($item['expr'], $ctx);
                $effectiveAlias = $item['alias'] ?? $parsedAlias;
                if ($effectiveAlias !== null && !($node instanceof AliasedProjectionNode)) {
                    $node = new AliasedProjectionNode(alias: $effectiveAlias, expression: $node);
                }
                $projNodes[] = $node;
            }
        }
        $projectionList = new ProjectionListNode(items: $projNodes);

        // 4. WHERE: AND stack
        $whereNode = null;
        if ($this->whereStack !== []) {
            if (count($this->whereStack) === 1) {
                $whereNode = $this->parseBoolExpr($this->whereStack[0], $ctx);
            } else {
                $parts = array_map(fn($e) => $this->parseBoolExpr($e, $ctx), $this->whereStack);
                $whereNode = self::andChain($parts);
            }
        }

        // 5. GROUP BY
        $groupByList = null;
        if ($this->groupByStack !== []) {
            $items = array_map(fn(string $e): SemanticNode => $this->parseExpression($e, $ctx, 'scalar'), $this->groupByStack);
            $groupByList = new \Quantum\Database\Operation\Sqg\Node\GroupByListNode(items: $items);
        }

        // 6. HAVING: AND stack
        $having = null;
        if ($this->havingStack !== []) {
            if (count($this->havingStack) === 1) {
                $havingNode = $this->parseBoolExpr($this->havingStack[0], $ctx);
            } else {
                $parts = array_map(fn($e) => $this->parseBoolExpr($e, $ctx), $this->havingStack);
                $havingNode = self::andChain($parts);
            }
            $having = new \Quantum\Database\Operation\Sqg\Node\HavingClauseNode(predicate: $havingNode);
        }

        // 7. ORDER BY
        $orderBy = null;
        if ($this->orderByStack !== []) {
            $items = [];
            foreach ($this->orderByStack as $o) {
                $node = $this->parseExpression($o['expr'], $ctx, 'scalar');
                $dir = $o['dir'] === Order::Desc ? OrderDirection::Desc : OrderDirection::Asc;
                $items[] = new OrderByItemNode(expression: $node, direction: $dir, nulls: SortNulls::Last);
            }
            $orderBy = new OrderByListNode(items: $items);
        }

        // 8. LIMIT / OFFSET
        $limit = $this->limitVal !== null ? new LimitClauseNode(limit: $this->limitVal) : null;
        $offset = $this->offsetVal !== null ? new OffsetClauseNode(offset: $this->offsetVal) : null;

        $root = new SelectStatementNode(
            projections: $projectionList,
            fromSources: $sources,
            joins: $joins,
            where: $whereNode,
            groupBy: $groupByList,
            having: $having,
            orderBy: $orderBy,
            limit: $limit,
            offset: $offset,
            distinct: $this->distinctFlag ? new DistinctModifierNode() : null,
        );

        // 9. Build positional params list from ctx->paramOrder + this->params bag.
        $positional = [];
        foreach ($ctx->paramOrder as $name) {
            if (!$this->params->has($name)) {
                throw new \RuntimeException("QueryBuilder: parámetro nombrado :{$name} usado en expresión pero no seteado vía setParameter().");
            }
            $positional[] = $this->params->get($name);
        }

        $graph = new SemanticQueryGraph(root: $root, parameters: $positional);

        // 10. Detect dialect + capability set
        $dialect = null;
        $caps = null;
        if ($this->connection !== null) {
            $di = $this->connection->getDriverInfo();
            $caps = DatabaseCapabilitySet::detectFromDriverInfo($di->driverName, $di->serverVersion ?? '');
            $dialect = \Quantum\Database\Dialect\Support\DialectFactory::forDriver($di->driverName);
        } else {
            $caps = DatabaseCapabilitySet::minimalSet('sqlite');
            $dialect = new SqliteDialect();
        }

        // 11. Certify via validate pipeline 5-passes (errors → violación fatal → exception QB-003)
        try {
            $cert = $graph->validate($caps);
        } catch (\Throwable $e) {
            throw new \RuntimeException('QueryBuilder SQG validate falló: ' . $e->getMessage(), previous: $e);
        }
        $violations = $cert->violations;
        foreach ($violations as $v) {
            if (($v['severity'] ?? 'error') === 'error') {
                throw new \RuntimeException('QueryBuilder SQG error de validación [' . ($v['code'] ?? '?') . ']: ' . ($v['message'] ?? ''));
            }
        }

        return ['graph' => $graph, 'positional' => $positional, 'dialect' => $dialect, 'caps' => $caps];
    }

    private function buildSqgOperation(
        SemanticQueryGraph $graph,
        DatabaseCapabilitySet $caps,
    ): SqgOperation {
        $certification = $graph->validate($caps);
        $optimizationInput = new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
            limits: $this->resolvePipelineLimits(),
        );
        $optimization = (new DefaultQueryOptimizer())->optimize($optimizationInput, $this->context);
        $plan = (new NoOpQueryPlanner())->plan($optimization, $this->context);

        return new SqgOperation(
            kind: OperationKind::SqgSelect,
            graph: $plan->graph,
            certificationFingerprint: $certification->fingerprint,
            optimizationResult: $optimization,
            planArtifact: $plan,
        );
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function resolvePipelineLimits(): array
    {
        return [
            'max_rows' => $this->context?->maxRows,
            'max_depth' => $this->context?->maxDepth,
            'has_deadline' => $this->context?->deadline !== null,
        ];
    }

    // =============== PARSERS DE EXPRESIONES → NODES =============================

    /**
     * Parse una projection (raw string) → devuelve [SemanticNode, ?string aliasFromAs]
     * Soporta:
     *   u.id                         → ColumnRef
     *   u.*                          → QualifiedStar
     *   *                            → Star
     *   u.email AS mail              → Aliased(ColumnRef, mail)
     *   COUNT(*) AS c                → Aliased(AggregateCount, c)
     *   AVG(u.age)                   → Aggregate
     */
    private function parseProjection(string $raw, SqgTranslatorContext $ctx): array
    {
        $s = trim($raw);
        // Case-insensitive ' AS ' alias detection
        if (preg_match('/\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i', $s, $m)) {
            $alias = $m[1];
            $expr = substr($s, 0, -strlen($m[0]));
            $node = $this->parseExpression(trim($expr), $ctx, 'scalar');
            return [$node, $alias];
        }
        return [$this->parseExpression($s, $ctx, 'scalar-or-star'), null];
    }

    /**
     * @param string|CompositeExpression $expr
     */
    private function parseBoolExpr(string|CompositeExpression $expr, SqgTranslatorContext $ctx): SemanticNode
    {
        if ($expr instanceof CompositeExpression) {
            $parts = array_map(fn($p) => $this->parseBoolExpr($p, $ctx), $expr->parts);
            return match ($expr->type) {
                CompositeExpression::TYPE_AND => self::andChain($parts),
                CompositeExpression::TYPE_OR  => self::orChain($parts),
                CompositeExpression::TYPE_NOT => new UnaryExpressionNode(operand: $parts[0], op: UnaryOperator::Not),
            };
        }
        return $this->parseExpression($expr, $ctx, 'boolean');
    }

    /**
     * Parse expression string V1 (subset completo del ExpressionBuilder output).
     *
     * @param 'scalar'|'boolean'|'scalar-or-star' $kind
     */
    private function parseExpression(string $expr, SqgTranslatorContext $ctx, string $kind): SemanticNode
    {
        $e = trim($expr);
        if ($e === '') {
            throw new \RuntimeException('QB parseExpression: empty string');
        }
        if ($kind === 'scalar-or-star') {
            if ($e === '*') {
                return new StarProjectionNode();
            }
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.\*$/', $e, $m)) {
                return new QualifiedStarProjectionNode(tableAlias: $m[1]);
            }
        }

        // Strip outermost parentheses
        while (str_starts_with($e, '(') && self::isMatchingOuterParens($e)) {
            $e = trim(substr($e, 1, -1));
        }

        // NOT expr (unary prefix)
        if (stripos($e, 'NOT ') === 0) {
            $inner = $this->parseBoolExpr(trim(substr($e, 4)), $ctx);
            return new UnaryExpressionNode(operand: $inner, op: UnaryOperator::Not);
        }

        // EXISTS (...)
        if (stripos($e, 'EXISTS') === 0) {
            // V1: tratamiento placeholder. Usamos Literal TRUE porque EXISTS subquery no está en V1.
            return new LiteralNode(true, DataType::Bool);
        }

        // AND / OR binary a nivel top-level con respeto a paréntesis
        $split = self::splitTopLevel($e, [' AND ', ' OR ']);
        if ($split !== null) {
            [$opRaw, $left, $right] = $split;
            $l = $this->parseBoolExpr($left, $ctx);
            $r = $this->parseBoolExpr($right, $ctx);
            $op = strtoupper(trim($opRaw)) === 'AND' ? BinaryOperator::AndAlso : BinaryOperator::OrElse;
            return new BinaryExpressionNode(left: $l, right: $r, op: $op);
        }

        // IS NULL / IS NOT NULL
        if (preg_match('/^(.*?)\s+IS\s+NOT\s+NULL$/i', $e, $m)) {
            $inner = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            return new UnaryExpressionNode(op: UnaryOperator::Not, operand: new IsNullPredicateNode(operand: $inner, negated: false));
        }
        if (preg_match('/^(.*?)\s+IS\s+NULL$/i', $e, $m)) {
            $inner = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            return new IsNullPredicateNode(operand: $inner, negated: false);
        }

        // BETWEEN
        if (preg_match('/^(.*?)\s+BETWEEN\s+(.+?)\s+AND\s+(.+?)$/is', $e, $m)) {
            $op = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $lo = $this->parseScalarValue(trim($m[2]), $ctx);
            $hi = $this->parseScalarValue(trim($m[3]), $ctx);
            return new BetweenPredicateNode(operand: $op, lower: $lo, upper: $hi, negated: false);
        }
        if (preg_match('/^(.*?)\s+NOT\s+BETWEEN\s+(.+?)\s+AND\s+(.+?)$/is', $e, $m)) {
            $op = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $lo = $this->parseScalarValue(trim($m[2]), $ctx);
            $hi = $this->parseScalarValue(trim($m[3]), $ctx);
            return new BetweenPredicateNode(operand: $op, lower: $lo, upper: $hi, negated: true);
        }

        // IN (...) / NOT IN (...)
        if (preg_match('/^(.*?)\s+IN\s*\((.*)\)$/is', $e, $m)) {
            $op = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $items = array_map('trim', explode(',', $m[2]));
            $nodes = array_map(fn($x) => $this->parseScalarValue($x, $ctx), $items);
            return new InListPredicateNode(operand: $op, list: $nodes, negated: false);
        }
        if (preg_match('/^(.*?)\s+NOT\s+IN\s*\((.*)\)$/is', $e, $m)) {
            $op = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $items = array_map('trim', explode(',', $m[2]));
            $nodes = array_map(fn($x) => $this->parseScalarValue($x, $ctx), $items);
            return new InListPredicateNode(operand: $op, list: $nodes, negated: true);
        }

        // LIKE / NOT LIKE
        if (preg_match('/^(.*?)\s+LIKE\s+(.+?)(?:\s+ESCAPE\s+(.+?))?$/is', $e, $m)) {
            $l = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $r = $this->parseScalarValue(trim($m[2]), $ctx);
            return new BinaryExpressionNode(left: $l, right: $r, op: BinaryOperator::Like);
        }
        if (preg_match('/^(.*?)\s+NOT\s+LIKE\s+(.+?)(?:\s+ESCAPE\s+(.+?))?$/is', $e, $m)) {
            $l = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $r = $this->parseScalarValue(trim($m[2]), $ctx);
            $likeNode = new BinaryExpressionNode(left: $l, right: $r, op: BinaryOperator::Like);
            return new UnaryExpressionNode(op: UnaryOperator::Not, operand: $likeNode);
        }

        // ILIKE (PgSQL nativo, emulado en otros dialects por NodeSqlEmitter)
        if (preg_match('/^(.*?)\s+ILIKE\s+(.+?)$/is', $e, $m)) {
            $l = $this->parseExpression(trim($m[1]), $ctx, 'scalar');
            $r = $this->parseScalarValue(trim($m[2]), $ctx);
            return new \Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode(left: $l, right: $r, op: BinaryOperator::ILike);
        }

        // Comparison: <>, !=, =, <=, >=, <, >
        $cmpSplit = self::splitTopLevelCmp($e);
        if ($cmpSplit !== null) {
            [$op, $l, $r] = $cmpSplit;
            $left = $this->parseExpression(trim($l), $ctx, 'scalar');
            $right = $this->parseScalarValue(trim($r), $ctx);
            $binOp = match ($op) {
                '='   => BinaryOperator::Eq,
                '<>', '!=' => BinaryOperator::NotEq,
                '<'   => BinaryOperator::Lt,
                '<='  => BinaryOperator::Lte,
                '>'   => BinaryOperator::Gt,
                '>='  => BinaryOperator::Gte,
            };
            return new BinaryExpressionNode(left: $left, right: $right, op: $binOp);
        }

        // Arithmetic +-* / %  (top-level binary)
        $arith = self::splitTopLevel($e, [' + ', ' - ', ' * ', ' / ', ' % ']);
        if ($arith !== null) {
            [$opRaw, $l, $r] = $arith;
            $left = $this->parseExpression(trim($l), $ctx, 'scalar');
            $right = $this->parseExpression(trim($r), $ctx, 'scalar');
            $op = match (trim($opRaw)) {
                '+' => BinaryOperator::Plus,
                '-' => BinaryOperator::Minus,
                '*' => BinaryOperator::Star,
                '/' => BinaryOperator::Slash,
                '%' => BinaryOperator::Percent,
            };
            return new BinaryExpressionNode(left: $left, right: $right, op: $op);
        }

        // Aggregate function call: FUNC(args) con FUNC conocida (COUNT/SUM/AVG/MIN/MAX/GROUP_CONCAT)
        if (preg_match('/^([A-Z_][A-Z0-9_]*)\s*\(\s*(.*?)\s*\)$/is', $e, $m)) {
            $fn = strtoupper($m[1]);
            $argsRaw = trim($m[2]);
            $distinctFlag = false;
            if (stripos($argsRaw, 'DISTINCT ') === 0) {
                $distinctFlag = true;
                $argsRaw = trim(substr($argsRaw, 9));
            }
            if ($argsRaw === '*') {
                $argNodes = [];
            } elseif ($argsRaw === '') {
                $argNodes = [];
            } else {
                $argNodes = array_map(fn($x) => $this->parseExpression(trim($x), $ctx, 'scalar'), explode(',', $argsRaw));
            }

            $kind = match ($fn) {
                'COUNT' => ($argsRaw === '*' ? AggregateFunctionKind::CountStar : AggregateFunctionKind::Count),
                'SUM'   => AggregateFunctionKind::Sum,
                'AVG'   => AggregateFunctionKind::Avg,
                'MIN'   => AggregateFunctionKind::Min,
                'MAX'   => AggregateFunctionKind::Max,
                'GROUP_CONCAT' => AggregateFunctionKind::GroupConcat,
                'STRING_AGG' => AggregateFunctionKind::StringAgg,
                'ARRAY_AGG' => AggregateFunctionKind::ArrayAgg,
                default => null,
            };
            if ($kind !== null) {
                $argsReal = ($kind === AggregateFunctionKind::CountStar && $argsRaw === '*') ? [] : $argNodes;
                return new AggregateFunctionNode(fn: $kind, args: $argsReal, distinct: $distinctFlag);
            }
            // Regular scalar SQL function: UPPER, LOWER, COALESCE, CONCAT, NOW(), etc.
            return match ($fn) {
                'NOW' => new FunctionCallNode(functionName: 'NOW', args: [], isMutable: true),
                'COALESCE', 'CONCAT', 'UPPER', 'LOWER', 'SUBSTR', 'TRIM', 'LENGTH', 'ABS', 'ROUND' => new FunctionCallNode(functionName: $fn, args: $argNodes, isMutable: false),
                default => new FunctionCallNode(functionName: $fn, args: $argNodes, isMutable: true),
            };
        }

        // Column reference alias.column
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_*][a-zA-Z0-9_]*)$/', $e, $m)) {
            return new ColumnReferenceNode(column: $m[2], tableAlias: $m[1]);
        }

        // Bare column name sin alias (para queries simple-table)
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $e)) {
            return new ColumnReferenceNode(column: $e);
        }

        throw new \RuntimeException("QueryBuilder parseExpression: no pudo parsear '{$e}'");
    }

    /**
     * Parse a RHS value (literal, :param) → ParameterNode or LiteralNode.
     */
    private function parseScalarValue(string $s, SqgTranslatorContext $ctx): SemanticNode
    {
        $s = trim($s);
        if ($s === '') {
            throw new \RuntimeException('Empty scalar value');
        }
        if (preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)$/', $s, $m)) {
            $name = $m[1];
            if (!array_key_exists($name, $ctx->paramIndex)) {
                $idx = count($ctx->paramOrder);
                $ctx->paramIndex[$name] = $idx;
                $ctx->paramOrder[] = $name;
            } else {
                $idx = $ctx->paramIndex[$name];
            }
            return new ParameterNode(index: $idx, name: $name);
        }
        // Literal NULL
        if (strtoupper($s) === 'NULL') {
            return new LiteralNode(null, DataType::Null);
        }
        // String '...'
        if (str_starts_with($s, "'") && preg_match('/^\'(.*)\'\z/s', $s, $m)) {
            $val = str_replace("''", "'", $m[1]);
            return new LiteralNode($val, DataType::Text);
        }
        // Int
        if (preg_match('/^-?[0-9]+\z/', $s)) {
            $v = (int)$s;
            return new LiteralNode($v, DataType::Int4);
        }
        // Float
        if (preg_match('/^-?[0-9]*\.[0-9]+(?:E[+-]?[0-9]+)?\z/i', $s)) {
            return new LiteralNode((float)$s, DataType::Float8);
        }
        // Boolean
        if (strtoupper($s) === 'TRUE' || strtoupper($s) === 'FALSE') {
            return new LiteralNode(strtoupper($s) === 'TRUE', DataType::Bool);
        }
        // Fallback: ColumnRef (para rhs que es alias.col en join on)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$/', $s, $mm)) {
            return new ColumnReferenceNode(column: $mm[2], tableAlias: $mm[1]);
        }
        // Fallback 2: bare column
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $s)) {
            return new ColumnReferenceNode(column: $s);
        }
        throw new \RuntimeException("parseScalarValue no soportado: '{$s}'");
    }

    // =================== STATIC HELPERS =========================================

    /** @param list<SemanticNode> $parts (>=2) */
    private static function andChain(array $parts): SemanticNode
    {
        if (count($parts) < 2) {
            return $parts[0];
        }
        $acc = new BinaryExpressionNode(left: $parts[0], right: $parts[1], op: BinaryOperator::AndAlso);
        for ($i = 2, $max = count($parts); $i < $max; $i++) {
            $acc = new BinaryExpressionNode(left: $acc, right: $parts[$i], op: BinaryOperator::AndAlso);
        }
        return $acc;
    }

    /** @param list<SemanticNode> $parts (>=2) */
    private static function orChain(array $parts): SemanticNode
    {
        if (count($parts) < 2) {
            return $parts[0];
        }
        $acc = new BinaryExpressionNode(left: $parts[0], right: $parts[1], op: BinaryOperator::OrElse);
        for ($i = 2, $max = count($parts); $i < $max; $i++) {
            $acc = new BinaryExpressionNode(left: $acc, right: $parts[$i], op: BinaryOperator::OrElse);
        }
        return $acc;
    }

    private static function isMatchingOuterParens(string $e): bool
    {
        $len = strlen($e);
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($e[$i] === '(') {
                $depth++;
            } elseif ($e[$i] === ')') {
                $depth--;
                if ($i !== $len - 1 && $depth === 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /**
     * Split $e en la primera ocurrencia de un operador de $ops a nivel paréntesis 0.
     * Devuelve [opRaw, leftStr, rightStr] o null si no.
     *
     * @param list<string> $ops
     * @return array{string,string,string}|null
     */
    private static function splitTopLevel(string $e, array $ops): ?array
    {
        $len = strlen($e);
        $depth = 0;
        $inS = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $e[$i];
            if ($ch === "'") {
                $inS = !$inS;
                continue;
            }
            if ($inS) {
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            foreach ($ops as $op) {
                $opLen = strlen($op);
                if ($i + $opLen > $len) {
                    continue;
                }
                if (0 === strcasecmp(substr($e, $i, $opLen), $op)) {
                    // Avoid splitting at '-' de número negativo: op=' - ' y antes hay =/>/</(/(/AND/OR/+,*
                    if ($op === ' - ' && $i === 0) {
                        continue;
                    }
                    $left = trim(substr($e, 0, $i));
                    $right = trim(substr($e, $i + $opLen));
                    return [$op, $left, $right];
                }
            }
        }
        return null;
    }

    /**
     * Comparación a nivel top-level (primera ocurrencia, 0 paréntesis).
     *
     * @return array{string,string,string}|null  [op, left, right]
     */
    private static function splitTopLevelCmp(string $e): ?array
    {
        $ops = ['<>', '!=', '<=', '>=', '=', '<', '>'];
        $len = strlen($e);
        $depth = 0;
        $inS = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $e[$i];
            if ($ch === "'") {
                $inS = !$inS;
                continue;
            }
            if ($inS) {
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            foreach ($ops as $op) {
                $opLen = strlen($op);
                if ($i + $opLen > $len) {
                    continue;
                }
                if (substr($e, $i, $opLen) === $op) {
                    $left = trim(substr($e, 0, $i));
                    $right = trim(substr($e, $i + $opLen));
                    // Asegurarse que no sean operadores compuestos: op '=' y la siguiente letra sea '>' o '<'
                    if ($op === '=' && $i + 1 < $len && in_array($e[$i + 1], ['>', '<'])) {
                        continue;
                    }
                    return [$op, $left, $right];
                }
            }
        }
        return null;
    }

    // =================== LOGGING: __toString named params =======================

    private function buildNamedParamSqlLog(): string
    {
        $parts = [];
        $parts[] = 'SELECT ' . ($this->distinctFlag ? 'DISTINCT ' : '') . implode(', ', array_map(fn($x) => $x['expr'] . ($x['alias'] ? ' AS ' . $x['alias'] : ''), $this->selectStack));
        foreach ($this->fromStack as $f) {
            $parts[] = 'FROM ' . $f['table'] . ' ' . $f['alias'];
        }
        foreach ($this->joinStack as $j) {
            $parts[] = $j['type']->value . ' JOIN ' . $j['join'] . ' ' . ($j['alias'] ?? '') . ($j['cond'] ? ' ON ' . $j['cond'] : '');
        }
        if ($this->whereStack !== []) {
            $parts[] = 'WHERE ' . implode(' AND ', array_map('strval', $this->whereStack));
        }
        if ($this->groupByStack !== []) {
            $parts[] = 'GROUP BY ' . implode(', ', $this->groupByStack);
        }
        if ($this->havingStack !== []) {
            $parts[] = 'HAVING ' . implode(' AND ', array_map('strval', $this->havingStack));
        }
        if ($this->orderByStack !== []) {
            $items = array_map(fn($o) => $o['expr'] . ' ' . $o['dir']->value, $this->orderByStack);
            $parts[] = 'ORDER BY ' . implode(', ', $items);
        }
        if ($this->limitVal !== null) {
            $parts[] = 'LIMIT ' . $this->limitVal;
        }
        if ($this->offsetVal !== null) {
            $parts[] = 'OFFSET ' . $this->offsetVal;
        }
        return implode("\n  ", $parts);
    }

    private function runtimePolicyFromContext(DatabaseContext $context): DatabaseExecutionPolicy
    {
        $timeoutMs = $context->deadline?->remainingMs() ?? 30000;

        return new DatabaseExecutionPolicy(
            timeoutMs: max(1, $timeoutMs),
            maxRows: max(1, $context->maxRows),
            maxDepth: max(1, $context->maxDepth),
        );
    }
}

/**
 * Estado mutable interno para traductor V1. No es API pública.
 *
 * @internal
 */
final class SqgTranslatorContext
{
    /** @var array<string,int> nombre → índice posicional */
    public array $paramIndex = [];
    /** @var list<string> orden de aparición (primera vez) de cada named param */
    public array $paramOrder = [];
}
