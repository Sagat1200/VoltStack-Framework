<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Sqg\Node\CteSourceNode;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
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

        $physicalStrategy = 'compile_sqg_direct';
        $physicalPlan = new QueryPhysicalPlan(
            rootStrategy: $physicalStrategy,
            strategies: [$physicalStrategy],
            metadata: [
                'planner_mode' => 'no_op',
            ],
        );

        $diagnostics = new QueryPlanDiagnostics(
            logicalOperators: $logicalPlan->operators,
            physicalStrategies: $physicalPlan->strategies,
            capabilityDecisions: ['Using direct SQG compilation as planner bootstrap strategy.'],
            warnings: [],
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