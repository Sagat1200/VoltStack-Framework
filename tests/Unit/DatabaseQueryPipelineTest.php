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
        self::assertSame('compile_sqg_direct', $plan->physicalPlan->rootStrategy);
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
        self::assertSame(1, $plan->logicalPlan->metadata['join_count'] ?? null);
        self::assertSame('table', $plan->logicalPlan->metadata['operator_details'][0]['metadata']['source_kind'] ?? null);
        self::assertSame('table', $plan->logicalPlan->metadata['operator_details'][1]['metadata']['source_kind'] ?? null);
        self::assertSame('Inner', $plan->logicalPlan->metadata['operator_details'][2]['metadata']['join_type'] ?? null);
        self::assertSame('where', $plan->logicalPlan->metadata['operator_details'][3]['metadata']['source'] ?? null);
        self::assertSame(2, $plan->logicalPlan->metadata['operator_details'][4]['metadata']['projection_count'] ?? null);
        self::assertSame(10, $plan->logicalPlan->metadata['operator_details'][6]['metadata']['value'] ?? null);
        self::assertSame(5, $plan->logicalPlan->metadata['operator_details'][7]['metadata']['value'] ?? null);
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
