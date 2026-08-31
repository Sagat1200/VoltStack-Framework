<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\UpdateStatementNode;
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

        $logicalOperator = $this->resolveLogicalRootOperator($graph);
        $logicalPlan = new QueryLogicalPlan(
            rootOperator: $logicalOperator,
            operators: [$logicalOperator],
            metadata: [
                'selected_candidate' => $optimized->selectedCandidate->id,
            ],
        );

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
            $physicalPlan->rootStrategy,
            (string) $bindingLayout->parameterCount,
        ]));
    }
}
