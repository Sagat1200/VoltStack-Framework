<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\ColumnReferenceNode;
use Quantum\Database\Operation\Sqg\Node\CteSourceNode;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Operation\Sqg\Node\OrderByItemNode;
use Quantum\Database\Operation\Sqg\Node\ParameterNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\SubquerySourceNode;
use Quantum\Database\Operation\Sqg\Node\TableSourceNode;
use Quantum\Database\Operation\Sqg\Node\UpdateStatementNode;
use Quantum\Database\Operation\Sqg\Node\ValuesSourceNode;
use Quantum\Database\Operation\Sqg\SemanticNode;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;

interface QueryPlannerInterface
{
    public function plan(QueryOptimizationResult $optimized, ?DatabaseContext $context = null): QueryPlanArtifact;
}

final readonly class QueryBindingLayout
{
    /**
     * @param list<int> $parameterOrder
     */
    public function __construct(
        public array $parameterOrder,
        public int $parameterCount,
    ) {}
}

final readonly class QueryLogicalPlan
{
    /**
     * @param list<string> $operators
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $rootOperator,
        public array $operators = [],
        public array $metadata = [],
    ) {}
}

final readonly class QueryPhysicalPlan
{
    /**
     * @param list<string> $strategies
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $rootStrategy,
        public array $strategies = [],
        public array $metadata = [],
    ) {}
}

final readonly class QueryPlanDiagnostics
{
    /**
     * @param list<string> $logicalOperators
     * @param list<string> $physicalStrategies
     * @param list<string> $capabilityDecisions
     * @param list<string> $warnings
     */
    public function __construct(
        public array $logicalOperators = [],
        public array $physicalStrategies = [],
        public array $capabilityDecisions = [],
        public array $warnings = [],
    ) {}
}

final readonly class QueryPlanArtifact
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $fingerprint,
        public SemanticQueryGraph $graph,
        public QueryLogicalPlan $logicalPlan,
        public QueryPhysicalPlan $physicalPlan,
        public QueryBindingLayout $bindingLayout,
        public QueryPlanDiagnostics $diagnostics,
        public array $metadata = [],
    ) {}
}

final class NoOpQueryPlanner implements QueryPlannerInterface
{
    public function plan(QueryOptimizationResult $optimized, ?DatabaseContext $context = null): QueryPlanArtifact
    {
        $graph = $optimized->graph;
        $bindingLayout = new QueryBindingLayout(
            parameterOrder: $this->resolveParameterOrder($graph),
            parameterCount: count($graph->parameters),
        );

        $logicalPlan = $this->buildLogicalPlan($graph, $optimized);
        [$physicalPlan, $capabilityDecisions, $warnings] = $this->buildPhysicalPlan($graph, $logicalPlan, $optimized);

        $diagnostics = new QueryPlanDiagnostics(
            logicalOperators: $logicalPlan->operators,
            physicalStrategies: $physicalPlan->strategies,
            capabilityDecisions: $capabilityDecisions,
            warnings: $warnings,
        );

        return new QueryPlanArtifact(
            fingerprint: $this->fingerprintFor($optimized, $bindingLayout, $logicalPlan, $physicalPlan),
            graph: $graph,
            logicalPlan: $logicalPlan,
            physicalPlan: $physicalPlan,
            bindingLayout: $bindingLayout,
            diagnostics: $diagnostics,
            metadata: [
                'request_id' => $context?->requestId,
                'optimizer_strategy' => $optimized->decision->strategy,
            ],
        );
    }

    private function buildLogicalPlan(
        SemanticQueryGraph $graph,
        QueryOptimizationResult $optimized,
    ): QueryLogicalPlan {
        if ($graph->root instanceof SelectStatementNode) {
            return $this->buildSelectLogicalPlan($graph->root, $optimized);
        }

        $logicalOperator = $this->resolveLogicalRootOperator($graph);

        return new QueryLogicalPlan(
            rootOperator: $logicalOperator,
            operators: [$logicalOperator],
            metadata: [
                'selected_candidate' => $optimized->selectedCandidate->id,
                'operator_details' => [
                    [
                        'name' => $logicalOperator,
                        'metadata' => [],
                    ],
                ],
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function resolveParameterOrder(SemanticQueryGraph $graph): array
    {
        if ($graph->parameters === []) {
            return [];
        }

        return range(0, count($graph->parameters) - 1);
    }

    private function resolveLogicalRootOperator(SemanticQueryGraph $graph): string
    {
        return match (true) {
            $graph->root instanceof SelectStatementNode => 'select',
            $graph->root instanceof InsertStatementNode => 'insert',
            $graph->root instanceof UpdateStatementNode => 'update',
            $graph->root instanceof DeleteStatementNode => 'delete',
            default => 'unknown',
        };
    }

    private function buildSelectLogicalPlan(
        SelectStatementNode $select,
        QueryOptimizationResult $optimized,
    ): QueryLogicalPlan {
        $operators = [];
        $details = [];

        foreach ($select->fromSources as $source) {
            $details[] = $this->buildScanOperatorDetail($source);
            $operators[] = 'scan';
        }

        foreach ($select->joins as $join) {
            if ($join instanceof JoinNode) {
                $details[] = $this->buildScanOperatorDetail($join->right);
                $operators[] = 'scan';
                $details[] = [
                    'name' => 'join',
                    'metadata' => [
                        'join_type' => $join->type->name,
                        'has_predicate' => $join->on !== null,
                    ],
                ];
                $operators[] = 'join';
            }
        }

        if ($select->where !== null) {
            $details[] = [
                'name' => 'filter',
                'metadata' => [
                    'source' => 'where',
                ],
            ];
            $operators[] = 'filter';
        }

        if ($select->groupBy !== null || $select->having !== null) {
            $details[] = [
                'name' => 'aggregate',
                'metadata' => [
                    'has_group_by' => $select->groupBy !== null,
                    'has_having' => $select->having !== null,
                ],
            ];
            $operators[] = 'aggregate';
        }

        $details[] = [
            'name' => 'project',
            'metadata' => [
                'projection_count' => $select->projections !== null ? count($select->projections->items) : 0,
                'distinct' => $select->distinct !== null,
            ],
        ];
        $operators[] = 'project';

        if ($select->orderBy !== null) {
            $details[] = [
                'name' => 'sort',
                'metadata' => [
                    'order_count' => count($select->orderBy->items),
                ],
            ];
            $operators[] = 'sort';
        }

        if ($select->limit !== null) {
            $details[] = [
                'name' => 'limit',
                'metadata' => [
                    'value' => $select->limit->limit,
                ],
            ];
            $operators[] = 'limit';
        }

        if ($select->offset !== null) {
            $details[] = [
                'name' => 'offset',
                'metadata' => [
                    'value' => $select->offset->offset,
                ],
            ];
            $operators[] = 'offset';
        }

        $rootOperator = $operators !== [] ? $operators[array_key_last($operators)] : 'project';

        return new QueryLogicalPlan(
            rootOperator: $rootOperator,
            operators: $operators !== [] ? $operators : ['project'],
            metadata: [
                'selected_candidate' => $optimized->selectedCandidate->id,
                'operator_details' => $details,
                'source_count' => count($select->fromSources),
                'join_count' => count($select->joins),
            ],
        );
    }

    /**
     * @return array{0:QueryPhysicalPlan,1:list<string>,2:list<string>}
     */
    private function buildPhysicalPlan(
        SemanticQueryGraph $graph,
        QueryLogicalPlan $logicalPlan,
        QueryOptimizationResult $optimized,
    ): array {
        if ($graph->root instanceof SelectStatementNode) {
            return $this->buildSelectPhysicalPlan($graph->root, $logicalPlan, $optimized->selectedCandidate->id);
        }

        $physicalStrategy = 'compile_sqg_direct';

        return [
            new QueryPhysicalPlan(
                rootStrategy: $physicalStrategy,
                strategies: [$physicalStrategy],
                metadata: [
                    'planner_mode' => 'no_op',
                    'strategy_details' => [
                        [
                            'name' => $physicalStrategy,
                            'metadata' => [],
                        ],
                    ],
                ],
            ),
            ['Using direct SQG compilation as planner bootstrap strategy.'],
            [],
        ];
    }

    /**
     * @return array{0:QueryPhysicalPlan,1:list<string>,2:list<string>}
     */
    private function buildSelectPhysicalPlan(
        SelectStatementNode $select,
        QueryLogicalPlan $logicalPlan,
        string $selectedCandidateId,
    ): array {
        $strategies = [];
        $strategyDetails = [];
        $capabilityDecisions = [];
        $warnings = [];

        $caps = $this->resolveCapabilitySet($select);

        foreach ($select->fromSources as $source) {
            [$strategy, $detail] = $this->resolveSourcePhysicalStrategy($source, $select);
            $strategies[] = $strategy;
            $strategyDetails[] = $detail;
            if (str_starts_with($strategy, 'index_')) {
                $capabilityDecisions[] = sprintf(
                    'Using %s for base source because planner found compatible predicate/order evidence on %s.',
                    $strategy,
                    $detail['metadata']['table'] ?? ($detail['metadata']['alias'] ?? 'source'),
                );
            }
        }

        foreach ($select->joins as $join) {
            if ($join instanceof JoinNode) {
                [$scanStrategy, $scanDetail] = $this->resolveSourcePhysicalStrategy($join->right, $select, $join->on);
                $strategies[] = $scanStrategy;
                $strategyDetails[] = $scanDetail;
                if (str_starts_with($scanStrategy, 'index_')) {
                    $capabilityDecisions[] = sprintf(
                        'Using %s for joined source because planner found compatible predicate/order evidence on %s.',
                        $scanStrategy,
                        $scanDetail['metadata']['table'] ?? ($scanDetail['metadata']['alias'] ?? 'joined_source'),
                    );
                }

                $joinStrategy = 'nested_loop_join';
                $strategies[] = $joinStrategy;
                $strategyDetails[] = [
                    'name' => $joinStrategy,
                    'metadata' => [
                        'join_type' => $join->type->name,
                        'has_predicate' => $join->on !== null,
                    ],
                ];
            }
        }

        if ($select->where !== null) {
            $strategies[] = 'predicate_evaluation';
            $strategyDetails[] = [
                'name' => 'predicate_evaluation',
                'metadata' => [
                    'source' => 'where',
                ],
            ];
        }

        if ($select->groupBy !== null || $select->having !== null) {
            $aggregateStrategy = $caps->windowFunctions ? 'aggregate_stream' : 'aggregate_materialize';
            $strategies[] = $aggregateStrategy;
            $strategyDetails[] = [
                'name' => $aggregateStrategy,
                'metadata' => [
                    'has_group_by' => $select->groupBy !== null,
                    'has_having' => $select->having !== null,
                ],
            ];
            $capabilityDecisions[] = $caps->windowFunctions
                ? 'Using aggregate_stream because windowFunctions capability is available.'
                : 'Using aggregate_materialize because windowFunctions capability is not available.';
        }

        $projectStrategy = $select->distinct !== null ? 'project_distinct' : 'project_passthrough';
        $strategies[] = $projectStrategy;
        $strategyDetails[] = [
            'name' => $projectStrategy,
            'metadata' => [
                'projection_count' => $select->projections !== null ? count($select->projections->items) : 0,
                'distinct' => $select->distinct !== null,
            ],
        ];

        if ($select->orderBy !== null) {
            $strategies[] = 'sort_materialize';
            $strategyDetails[] = [
                'name' => 'sort_materialize',
                'metadata' => [
                    'order_count' => count($select->orderBy->items),
                ],
            ];
            $capabilityDecisions[] = 'Using sort_materialize because logical ordering requires a stable physical sort step.';
        }

        if ($select->limit !== null || $select->offset !== null) {
            $strategies[] = 'streaming_limit';
            $strategyDetails[] = [
                'name' => 'streaming_limit',
                'metadata' => [
                    'limit' => $select->limit?->limit,
                    'offset' => $select->offset?->offset,
                ],
            ];
            $capabilityDecisions[] = 'Using streaming_limit to preserve pagination as a terminal physical step.';
        }

        if ($caps->multipleActiveResultSets) {
            $capabilityDecisions[] = 'multipleActiveResultSets is available; planner keeps direct SQG compilation compatible with streaming reads.';
        } else {
            $warnings[] = 'multipleActiveResultSets is not available; nested subqueries may require future buffering strategies.';
        }

        $rootStrategy = $strategies !== [] ? $strategies[array_key_last($strategies)] : 'project_passthrough';

        return [
            new QueryPhysicalPlan(
                rootStrategy: $rootStrategy,
                strategies: $strategies !== [] ? $strategies : ['project_passthrough'],
                metadata: [
                    'planner_mode' => 'no_op',
                    'selected_candidate' => $selectedCandidateId,
                    'strategy_details' => $strategyDetails,
                    'logical_root_operator' => $logicalPlan->rootOperator,
                ],
            ),
            $capabilityDecisions !== [] ? $capabilityDecisions : ['Using direct SQG compilation as planner bootstrap strategy.'],
            $warnings,
        ];
    }

    /**
     * @return array{name:string,metadata:array<string,mixed>}
     */
    private function buildScanOperatorDetail(SemanticNode $source): array
    {
        return match (true) {
            $source instanceof TableSourceNode => [
                'name' => 'scan',
                'metadata' => [
                    'source_kind' => 'table',
                    'table' => $source->tableName,
                    'alias' => $source->alias,
                    'schema' => $source->schema,
                ],
            ],
            $source instanceof SubquerySourceNode => [
                'name' => 'scan',
                'metadata' => [
                    'source_kind' => 'subquery',
                    'alias' => $source->alias,
                    'lateral' => $source->lateral,
                ],
            ],
            $source instanceof ValuesSourceNode => [
                'name' => 'scan',
                'metadata' => [
                    'source_kind' => 'values',
                    'alias' => $source->alias,
                    'row_count' => $source->rowCount,
                    'column_count' => $source->columnCount,
                ],
            ],
            $source instanceof CteSourceNode => [
                'name' => 'scan',
                'metadata' => [
                    'source_kind' => 'cte',
                    'name' => $source->name,
                    'column_aliases' => $source->columnAliases ?? [],
                ],
            ],
            default => [
                'name' => 'scan',
                'metadata' => [
                    'source_kind' => 'unknown',
                ],
            ],
        };
    }

    private function resolveCapabilitySet(SelectStatementNode $select): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::minimalSet('planner_v1');
    }

    /**
     * @return array{0:string,1:array{name:string,metadata:array<string,mixed>}}
     */
    private function resolveSourcePhysicalStrategy(
        SemanticNode $source,
        ?SelectStatementNode $select = null,
        ?SemanticNode $joinPredicate = null,
    ): array {
        return match (true) {
            $source instanceof TableSourceNode => $this->resolveTableSourcePhysicalStrategy($source, $select, $joinPredicate),
            $source instanceof ValuesSourceNode => [
                'values_scan',
                [
                    'name' => 'values_scan',
                    'metadata' => [
                        'row_count' => $source->rowCount,
                        'column_count' => $source->columnCount,
                        'alias' => $source->alias,
                    ],
                ],
            ],
            $source instanceof SubquerySourceNode => [
                'subquery_scan',
                [
                    'name' => 'subquery_scan',
                    'metadata' => [
                        'alias' => $source->alias,
                        'lateral' => $source->lateral,
                    ],
                ],
            ],
            $source instanceof CteSourceNode => [
                'cte_scan',
                [
                    'name' => 'cte_scan',
                    'metadata' => [
                        'name' => $source->name,
                        'column_aliases' => $source->columnAliases ?? [],
                    ],
                ],
            ],
            default => [
                'compile_sqg_direct',
                [
                    'name' => 'compile_sqg_direct',
                    'metadata' => [],
                ],
            ],
        };
    }

    /**
     * @return array{0:string,1:array{name:string,metadata:array<string,mixed>}}
     */
    private function resolveTableSourcePhysicalStrategy(
        TableSourceNode $source,
        ?SelectStatementNode $select,
        ?SemanticNode $joinPredicate = null,
    ): array {
        $alias = $source->aliasOrName();
        $evidence = [];

        if ($select !== null && $select->where !== null) {
            $evidence = [...$evidence, ...$this->collectIndexEvidenceForAlias($select->where, $alias, 'where')];
        }

        if ($select !== null) {
            foreach ($select->joins as $joinNode) {
                if ($joinNode instanceof JoinNode && $joinNode->on !== null) {
                    $evidence = [...$evidence, ...$this->collectIndexEvidenceForAlias($joinNode->on, $alias, 'join_on')];
                }
            }
        }

        if ($select !== null && $select->orderBy !== null) {
            foreach ($select->orderBy->items as $item) {
                if ($item instanceof OrderByItemNode) {
                    $evidence = [...$evidence, ...$this->collectOrderByEvidenceForAlias($item, $alias)];
                }
            }
        }

        if ($joinPredicate !== null) {
            $evidence = [...$evidence, ...$this->collectIndexEvidenceForAlias($joinPredicate, $alias, 'join_on')];
        }

        $evidence = $this->deduplicateEvidence($evidence);

        if ($evidence !== []) {
            $strategy = $this->classifyIndexCandidateStrategy($evidence);
            return [
                $strategy,
                [
                    'name' => $strategy,
                    'metadata' => [
                        'table' => $source->tableName,
                        'alias' => $source->alias,
                        'evidence' => $evidence,
                        'evidence_classification' => $strategy,
                    ],
                ],
            ];
        }

        return [
            'table_scan',
            [
                'name' => 'table_scan',
                'metadata' => [
                    'table' => $source->tableName,
                    'alias' => $source->alias,
                ],
            ],
        ];
    }

    /**
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function collectIndexEvidenceForAlias(
        SemanticNode $node,
        string $alias,
        string $source,
    ): array {
        $evidence = [];

        if ($node instanceof BinaryExpressionNode) {
            if (in_array($node->op, [
                BinaryOperator::Eq,
                BinaryOperator::Lt,
                BinaryOperator::Lte,
                BinaryOperator::Gt,
                BinaryOperator::Gte,
            ], true)) {
                $leftEvidence = $this->matchIndexableComparisonSide($node->left, $node->right, $alias, $source, $node->op);
                $rightEvidence = $this->matchIndexableComparisonSide($node->right, $node->left, $alias, $source, $node->op);
                $evidence = [...$evidence, ...$leftEvidence, ...$rightEvidence];
            }

            foreach ($node->children() as $child) {
                if ($child instanceof SemanticNode) {
                    $evidence = [...$evidence, ...$this->collectIndexEvidenceForAlias($child, $alias, $source)];
                }
            }
        }

        return $this->deduplicateEvidence($evidence);
    }

    /**
     * @param list<array{source:string,column:string,operator:string,comparable_kind:string}> $evidence
     */
    private function classifyIndexCandidateStrategy(array $evidence): string
    {
        $hasLookup = false;
        $hasRange = false;
        $hasOrder = false;
        $lookupColumns = [];
        $rangeColumns = [];
        $orderColumns = [];
        $whereLookupColumns = [];
        $joinLookupColumns = [];
        $joinLiteralLookupColumns = [];
        $joinRelationalLookupColumns = [];

        foreach ($evidence as $item) {
            if ($item['operator'] === '=') {
                if ($item['comparable_kind'] === 'literal_or_param' || $item['source'] === 'where') {
                    $hasLookup = true;
                    $lookupColumns[$item['column']] = true;
                }
                if ($item['source'] === 'where') {
                    $whereLookupColumns[$item['column']] = true;
                }

                if ($item['source'] === 'join_on') {
                    $joinLookupColumns[$item['column']] = true;
                    if ($item['comparable_kind'] === 'literal_or_param') {
                        $joinLiteralLookupColumns[$item['column']] = true;
                    }
                    if ($item['comparable_kind'] === 'column_ref') {
                        $joinRelationalLookupColumns[$item['column']] = true;
                    }
                }
                continue;
            }

            if (in_array($item['operator'], ['<', '<=', '>', '>='], true)) {
                $hasRange = true;
                $rangeColumns[$item['column']] = true;
                continue;
            }

            if ($item['operator'] === 'order') {
                $hasOrder = true;
                $orderColumns[$item['column']] = true;
            }
        }

        $hasCompositeLookup = count($lookupColumns) >= 2;
        $hasJoinLookupCompound = $this->sharesEvidenceColumn($joinLiteralLookupColumns, $joinRelationalLookupColumns);
        $hasJoinWhereLookupCompound = $this->sharesEvidenceColumn($whereLookupColumns, $joinLookupColumns) || $hasJoinLookupCompound;
        $hasLookupOrderCompound = $hasLookup && $hasOrder && $this->sharesEvidenceColumn($lookupColumns, $orderColumns);
        $hasRangeOrderCompound = $hasRange && $hasOrder && $this->sharesEvidenceColumn($rangeColumns, $orderColumns);
        $hasCompositeLookupOrderCompound = $hasCompositeLookup && $hasOrder && $this->sharesEvidenceColumn($lookupColumns, $orderColumns);
        $joinOrderColumns = $hasJoinLookupCompound
            ? $this->intersectEvidenceColumns($joinLiteralLookupColumns, $joinRelationalLookupColumns)
            : $this->intersectEvidenceColumns($whereLookupColumns, $joinLookupColumns);
        $hasJoinWhereLookupOrderCompound = $hasJoinWhereLookupCompound
            && $this->sharesEvidenceColumn($joinOrderColumns, $orderColumns);

        return match (true) {
            $hasJoinWhereLookupOrderCompound => 'index_join_lookup_order_candidate',
            $hasJoinWhereLookupCompound => 'index_join_lookup_candidate',
            $hasCompositeLookupOrderCompound => 'index_composite_lookup_order_candidate',
            $hasCompositeLookup => 'index_composite_lookup_candidate',
            $hasLookupOrderCompound => 'index_lookup_order_candidate',
            $hasRangeOrderCompound => 'index_range_order_candidate',
            $hasLookup => 'index_lookup_candidate',
            $hasRange => 'index_range_candidate',
            $hasOrder => 'index_order_candidate',
            default => 'index_candidate',
        };
    }

    /**
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function collectOrderByEvidenceForAlias(OrderByItemNode $item, string $alias): array
    {
        if ($item->expression instanceof ColumnReferenceNode && $this->matchesAlias($item->expression, $alias)) {
            return [[
                'source' => 'order_by',
                'column' => $item->expression->column,
                'operator' => 'order',
                'comparable_kind' => 'order_by',
            ]];
        }

        return [];
    }

    /**
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function matchIndexableComparisonSide(
        SemanticNode $candidateColumn,
        SemanticNode $otherSide,
        string $alias,
        string $source,
        BinaryOperator $operator,
    ): array {
        if (
            !$candidateColumn instanceof ColumnReferenceNode
            || !$this->matchesAlias($candidateColumn, $alias)
            || !$this->isIndexComparableNode($otherSide)
        ) {
            return [];
        }

        return [[
            'source' => $source,
            'column' => $candidateColumn->column,
            'operator' => $operator->value,
            'comparable_kind' => $this->classifyComparableNode($otherSide),
        ]];
    }

    private function matchesAlias(ColumnReferenceNode $column, string $alias): bool
    {
        return $column->tableAlias === null || $column->tableAlias === $alias;
    }

    private function isIndexComparableNode(SemanticNode $node): bool
    {
        return $node instanceof LiteralNode || $node instanceof ParameterNode || $node instanceof ColumnReferenceNode;
    }

    /**
     * @param list<array{source:string,column:string,operator:string,comparable_kind:string}> $evidence
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function deduplicateEvidence(array $evidence): array
    {
        $unique = [];
        $seen = [];

        foreach ($evidence as $item) {
            $key = implode('|', [$item['source'], $item['column'], $item['operator'], $item['comparable_kind']]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    private function classifyComparableNode(SemanticNode $node): string
    {
        return match (true) {
            $node instanceof LiteralNode, $node instanceof ParameterNode => 'literal_or_param',
            $node instanceof ColumnReferenceNode => 'column_ref',
            default => 'other',
        };
    }

    /**
     * @param array<string, bool> $left
     * @param array<string, bool> $right
     */
    private function sharesEvidenceColumn(array $left, array $right): bool
    {
        foreach (array_keys($left) as $column) {
            if (isset($right[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $left
     * @param array<string, bool> $right
     * @return array<string, bool>
     */
    private function intersectEvidenceColumns(array $left, array $right): array
    {
        $intersection = [];

        foreach (array_keys($left) as $column) {
            if (isset($right[$column])) {
                $intersection[$column] = true;
            }
        }

        return $intersection;
    }

    private function fingerprintFor(
        QueryOptimizationResult $optimized,
        QueryBindingLayout $bindingLayout,
        QueryLogicalPlan $logicalPlan,
        QueryPhysicalPlan $physicalPlan,
    ): string {
        return hash('sha256', implode('|', [
            'voltstack-query-plan-v1',
            $optimized->certification->fingerprint,
            $optimized->decision->strategy,
            $optimized->selectedCandidate->id,
            $logicalPlan->rootOperator,
            implode(',', $logicalPlan->operators),
            $physicalPlan->rootStrategy,
            (string) $bindingLayout->parameterCount,
        ]));
    }
}
