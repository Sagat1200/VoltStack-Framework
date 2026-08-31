<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dialect\Support\SqliteDialect;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\Pipeline\DefaultQueryOptimizer;
use Quantum\Database\Operation\Pipeline\NoOpQueryPlanner;
use Quantum\Database\Operation\Pipeline\QueryOptimizationInput;
use Quantum\Database\Operation\SqgOperation;
use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode as SqgLiteralNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;
use Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Query\SelectQueryBuilder;

final class DatabaseQueryPipelineTest extends TestCase
{
    public function test_default_optimizer_returns_trace_and_preserves_graph_when_nothing_is_foldable(): void
    {
        [$graph, $caps, $certification] = $this->buildSimpleSelectGraph();

        $result = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        self::assertSame($graph, $result->graph);
        self::assertSame($certification->fingerprint, $result->certification->fingerprint);
        self::assertSame('no_op', $result->decision->strategy);
        self::assertSame('candidate:no_op', $result->selectedCandidate->id);
        self::assertGreaterThan(0, $result->selectedCandidate->estimatedCost);
        self::assertSame('deterministic_v1', $result->decision->metadata['cost_model_version'] ?? null);
        self::assertArrayHasKey('cardinality_cost', $result->selectedCandidate->costBreakdown);
        self::assertArrayHasKey('memory_risk_penalty', $result->selectedCandidate->costBreakdown);
        self::assertSame($result->selectedCandidate->costBreakdown, $result->decision->metadata['selected_breakdown'] ?? null);
        self::assertSame(1, $result->decision->metadata['candidate_count'] ?? null);
        self::assertNotEmpty($result->trace->candidateSummaries[0]['metrics'] ?? []);
        self::assertNotEmpty($result->trace->notes);
    }

    public function test_default_optimizer_folds_constant_projection_expression_into_literal(): void
    {
        $graph = $this->buildConstantProjectionGraph();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);

        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        self::assertSame('constant_folding_v1', $optimization->decision->strategy);
        self::assertSame('candidate:constant_folding_v1', $optimization->selectedCandidate->id);
        self::assertSame(['constant_folding_v1'], $optimization->trace->appliedRules);
        self::assertNotSame($graph, $optimization->graph);
        self::assertSame('deterministic_v1', $optimization->decision->metadata['cost_model_version'] ?? null);
        self::assertCount(2, $optimization->trace->candidateSummaries);
        self::assertGreaterThan(
            $optimization->trace->candidateSummaries[1]['cost'] ?? 0,
            $optimization->trace->candidateSummaries[0]['cost'] ?? 0,
        );
        self::assertGreaterThan(0, $optimization->decision->metadata['cost_delta_vs_baseline'] ?? 0);
        self::assertSame(
            $optimization->selectedCandidate->costBreakdown,
            $optimization->decision->metadata['selected_breakdown'] ?? null,
        );
        self::assertInstanceOf(AliasedProjectionNode::class, $optimization->graph->root->projections?->items[0] ?? null);
        self::assertInstanceOf(LiteralNode::class, $optimization->graph->root->projections?->items[0]->expression ?? null);
        self::assertSame(3, $optimization->graph->root->projections?->items[0]->expression->value ?? null);
    }

    public function test_no_op_planner_emits_plan_artifact_with_deterministic_shape(): void
    {
        [$graph, $caps, $certification] = $this->buildSimpleSelectGraph();
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame($graph, $plan->graph);
        self::assertSame('project', $plan->logicalPlan->rootOperator);
        self::assertSame(['scan', 'project'], $plan->logicalPlan->operators);
        self::assertSame('table', $plan->logicalPlan->metadata['operator_details'][0]['metadata']['source_kind'] ?? null);
        self::assertSame('project_passthrough', $plan->physicalPlan->rootStrategy);
        self::assertSame(['table_scan', 'project_passthrough'], $plan->physicalPlan->strategies);
        self::assertSame('table_scan', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertSame('project_passthrough', $plan->physicalPlan->metadata['strategy_details'][1]['name'] ?? null);
        self::assertNotEmpty($plan->diagnostics->warnings);
        self::assertSame(0, $plan->bindingLayout->parameterCount);
        self::assertNotEmpty($plan->fingerprint);
    }

    public function test_planner_extracts_explicit_logical_operators_for_joined_select_pipeline(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->innerJoin('u', 'profiles', 'p', 'u.id = p.user_id')
            ->select(['u.id', 'p.user_id'])
            ->where('u.id = 1')
            ->orderBy('u.id')
            ->setMaxResults(10)
            ->setFirstResult(5);
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame('offset', $plan->logicalPlan->rootOperator);
        self::assertSame(['scan', 'scan', 'join', 'filter', 'project', 'sort', 'limit', 'offset'], $plan->logicalPlan->operators);
        self::assertSame('streaming_limit', $plan->physicalPlan->rootStrategy);
        self::assertSame(
            ['index_lookup_order_candidate', 'index_lookup_candidate', 'nested_loop_join', 'predicate_evaluation', 'project_passthrough', 'sort_materialize', 'streaming_limit'],
            $plan->physicalPlan->strategies,
        );
        self::assertSame(1, $plan->logicalPlan->metadata['join_count'] ?? null);
        self::assertSame('table', $plan->logicalPlan->metadata['operator_details'][0]['metadata']['source_kind'] ?? null);
        self::assertSame('table', $plan->logicalPlan->metadata['operator_details'][1]['metadata']['source_kind'] ?? null);
        self::assertSame('Inner', $plan->logicalPlan->metadata['operator_details'][2]['metadata']['join_type'] ?? null);
        self::assertSame('where', $plan->logicalPlan->metadata['operator_details'][3]['metadata']['source'] ?? null);
        self::assertSame(2, $plan->logicalPlan->metadata['operator_details'][4]['metadata']['projection_count'] ?? null);
        self::assertSame(10, $plan->logicalPlan->metadata['operator_details'][6]['metadata']['value'] ?? null);
        self::assertSame(5, $plan->logicalPlan->metadata['operator_details'][7]['metadata']['value'] ?? null);
        self::assertSame('nested_loop_join', $plan->physicalPlan->metadata['strategy_details'][2]['name'] ?? null);
        self::assertSame('index_lookup_order_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertSame('id', $plan->physicalPlan->metadata['strategy_details'][0]['metadata']['evidence'][0]['column'] ?? null);
        self::assertSame('where', $plan->physicalPlan->metadata['strategy_details'][0]['metadata']['evidence'][0]['source'] ?? null);
        self::assertSame('index_lookup_candidate', $plan->physicalPlan->metadata['strategy_details'][1]['name'] ?? null);
        self::assertSame('sort_materialize', $plan->physicalPlan->metadata['strategy_details'][5]['name'] ?? null);
        self::assertSame(10, $plan->physicalPlan->metadata['strategy_details'][6]['metadata']['limit'] ?? null);
        self::assertSame(5, $plan->physicalPlan->metadata['strategy_details'][6]['metadata']['offset'] ?? null);
        self::assertNotEmpty($plan->diagnostics->capabilityDecisions);
    }

    public function test_planner_uses_index_range_candidate_for_simple_range_predicate(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->where('u.id >= 10');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame(['index_range_candidate', 'predicate_evaluation', 'project_passthrough'], $plan->physicalPlan->strategies);
        self::assertSame('index_range_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertSame('>=', $plan->physicalPlan->metadata['strategy_details'][0]['metadata']['evidence'][0]['operator'] ?? null);
        self::assertStringContainsString('index_range_candidate', $plan->diagnostics->capabilityDecisions[0] ?? '');
    }

    public function test_planner_uses_index_composite_lookup_candidate_for_multiple_equalities(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->where('u.id = 1')
            ->andWhere('u.status = 2');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame(['index_composite_lookup_candidate', 'predicate_evaluation', 'project_passthrough'], $plan->physicalPlan->strategies);
        self::assertSame('index_composite_lookup_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertStringContainsString('index_composite_lookup_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_planner_uses_index_composite_lookup_order_candidate_for_multi_column_lookup_with_order(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->where('u.id = 1')
            ->andWhere('u.status = 2')
            ->orderBy('u.status');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame(['index_composite_lookup_order_candidate', 'predicate_evaluation', 'project_passthrough', 'sort_materialize'], $plan->physicalPlan->strategies);
        self::assertSame('index_composite_lookup_order_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertStringContainsString('index_composite_lookup_order_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_planner_uses_index_join_lookup_candidate_for_joined_source_with_matching_join_and_where(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->innerJoin('u', 'profiles', 'p', 'u.id = p.user_id')
            ->select('p.user_id')
            ->where('p.user_id = 1');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame('predicate_pushdown_v1', $optimization->decision->strategy);
        self::assertNull($optimization->graph->root->where);
        self::assertSame(['predicate_pushdown_v1'], $optimization->decision->metadata['selected_rules'] ?? null);
        self::assertSame(['table_scan', 'index_join_lookup_candidate', 'nested_loop_join', 'project_passthrough'], $plan->physicalPlan->strategies);
        self::assertSame('index_join_lookup_candidate', $plan->physicalPlan->metadata['strategy_details'][1]['name'] ?? null);
        self::assertStringContainsString('index_join_lookup_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_planner_uses_index_join_lookup_order_candidate_for_joined_source_with_matching_join_where_and_order(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->innerJoin('u', 'profiles', 'p', 'u.id = p.user_id')
            ->select('p.user_id')
            ->where('p.user_id = 1')
            ->orderBy('p.user_id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame('predicate_pushdown_v1', $optimization->decision->strategy);
        self::assertNull($optimization->graph->root->where);
        self::assertSame(['table_scan', 'index_join_lookup_order_candidate', 'nested_loop_join', 'project_passthrough', 'sort_materialize'], $plan->physicalPlan->strategies);
        self::assertSame('index_join_lookup_order_candidate', $plan->physicalPlan->metadata['strategy_details'][1]['name'] ?? null);
        self::assertStringContainsString('index_join_lookup_order_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_default_optimizer_pushes_join_scoped_where_predicate_into_inner_join_on(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->innerJoin('u', 'profiles', 'p', 'u.id = p.user_id')
            ->select('p.user_id')
            ->where('p.user_id = 1');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);

        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        self::assertSame('predicate_pushdown_v1', $optimization->decision->strategy);
        self::assertSame(['predicate_pushdown_v1'], $optimization->trace->appliedRules);
        self::assertNull($optimization->graph->root->where);
        self::assertInstanceOf(BinaryExpressionNode::class, $optimization->graph->root->joins[0]->on ?? null);
        self::assertSame('candidate:predicate_pushdown_v1', $optimization->selectedCandidate->id);
        self::assertSame(
            'conjunctive_predicates_pushed_into_inner_join',
            $optimization->decision->metadata['reason'] ?? null,
        );
    }

    public function test_planner_uses_index_range_order_candidate_for_range_with_matching_order(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->where('u.id >= 10')
            ->orderBy('u.id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame(['index_range_order_candidate', 'predicate_evaluation', 'project_passthrough', 'sort_materialize'], $plan->physicalPlan->strategies);
        self::assertSame('index_range_order_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertStringContainsString('index_range_order_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_planner_uses_index_order_candidate_for_order_only_query(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->orderBy('u.id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame(['index_order_candidate', 'project_passthrough', 'sort_materialize'], $plan->physicalPlan->strategies);
        self::assertSame('index_order_candidate', $plan->physicalPlan->metadata['strategy_details'][0]['name'] ?? null);
        self::assertSame('order', $plan->physicalPlan->metadata['strategy_details'][0]['metadata']['evidence'][0]['operator'] ?? null);
        self::assertStringContainsString('index_order_candidate', implode(' ', $plan->diagnostics->capabilityDecisions));
    }

    public function test_default_optimizer_simplifies_offset_zero_into_no_offset_candidate(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->orderBy('u.id')
            ->setFirstResult(0);
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);

        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        self::assertSame('limit_offset_simplification_v1', $optimization->decision->strategy);
        self::assertSame(['limit_offset_simplification_v1'], $optimization->trace->appliedRules);
        self::assertSame(['limit_offset_simplification_v1'], $optimization->decision->metadata['selected_rules'] ?? null);
        self::assertNotNull($graph->root->offset);
        self::assertNull($optimization->graph->root->offset);
        self::assertGreaterThan(0, $optimization->decision->metadata['cost_delta_vs_baseline'] ?? 0);
    }

    public function test_default_optimizer_drops_offset_when_limit_zero_already_forces_empty_result(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id')
            ->setMaxResults(0)
            ->setFirstResult(25);
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);

        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        self::assertSame('limit_offset_simplification_v1', $optimization->decision->strategy);
        self::assertNotNull($graph->root->offset);
        self::assertNotNull($optimization->graph->root->limit);
        self::assertSame(0, $optimization->graph->root->limit?->limit);
        self::assertNull($optimization->graph->root->offset);
        self::assertSame(
            'candidate:limit_offset_simplification_v1',
            $optimization->selectedCandidate->id,
        );
        self::assertGreaterThan(0, $optimization->decision->metadata['cost_delta_vs_baseline'] ?? 0);
    }

    public function test_sqlite_dialect_compiles_sqg_operation_using_plan_artifact_fingerprint(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));
        $plan = (new NoOpQueryPlanner())->plan($optimization);

        $sql = $builder->getSQL();
        $compiled = (new SqliteDialect())->compile(new SqgOperation(
            kind: OperationKind::SqgSelect,
            graph: $graph,
            certificationFingerprint: $certification->fingerprint,
            optimizationResult: $optimization,
            planArtifact: $plan,
        ), $caps);

        self::assertStringContainsString('SELECT', strtoupper($sql));
        self::assertStringContainsString('FROM', strtoupper($sql));
        self::assertSame($plan->fingerprint, $compiled->fingerprint);
        self::assertSame($compiled->sql, $sql);
    }

    public function test_sqlite_dialect_compiles_folded_constant_projection_sql(): void
    {
        $graph = $this->buildConstantProjectionGraph();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new DefaultQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));
        $plan = (new NoOpQueryPlanner())->plan($optimization);
        $compiled = (new SqliteDialect())->compile(new SqgOperation(
            kind: OperationKind::SqgSelect,
            graph: $graph,
            certificationFingerprint: $certification->fingerprint,
            optimizationResult: $optimization,
            planArtifact: $plan,
        ), $caps);

        self::assertStringContainsString('SELECT 3 AS "folded_total"', $compiled->sql);
    }

    /**
     * @return array{0:\Quantum\Database\Operation\Sqg\SemanticQueryGraph,1:DatabaseCapabilitySet,2:\Quantum\Database\Operation\Sqg\GraphCertification}
     */
    private function buildSimpleSelectGraph(): array
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');

        return [$graph, $caps, $graph->validate($caps)];
    }

    private function buildConstantProjectionGraph(): SemanticQueryGraph
    {
        return new SemanticQueryGraph(
            root: new SelectStatementNode(
                projections: new ProjectionListNode(items: [
                    new AliasedProjectionNode(
                        expression: new BinaryExpressionNode(
                            op: BinaryOperator::Plus,
                            left: new SqgLiteralNode(value: 1, declaredType: DataType::Int8),
                            right: new SqgLiteralNode(value: 2, declaredType: DataType::Int8),
                        ),
                        alias: 'folded_total',
                    ),
                ]),
            ),
            parameters: [],
        );
    }
}
