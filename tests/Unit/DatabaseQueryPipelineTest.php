<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dialect\Support\SqliteDialect;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\Pipeline\NoOpQueryOptimizer;
use Quantum\Database\Operation\Pipeline\NoOpQueryPlanner;
use Quantum\Database\Operation\Pipeline\QueryOptimizationInput;
use Quantum\Database\Operation\SqgOperation;
use Quantum\Database\Query\SelectQueryBuilder;

final class DatabaseQueryPipelineTest extends TestCase
{
    public function test_no_op_optimizer_returns_trace_and_preserves_certified_graph(): void
    {
        [$graph, $caps, $certification] = $this->buildSimpleSelectGraph();

        $result = (new NoOpQueryOptimizer())->optimize(new QueryOptimizationInput(
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

    public function test_no_op_planner_emits_plan_artifact_with_deterministic_shape(): void
    {
        [$graph, $caps, $certification] = $this->buildSimpleSelectGraph();
        $optimization = (new NoOpQueryOptimizer())->optimize(new QueryOptimizationInput(
            graph: $graph,
            certification: $certification,
            capabilities: $caps,
        ));

        $plan = (new NoOpQueryPlanner())->plan($optimization);

        self::assertSame($graph, $plan->graph);
        self::assertSame('select', $plan->logicalPlan->rootOperator);
        self::assertSame('compile_sqg_direct', $plan->physicalPlan->rootStrategy);
        self::assertSame(0, $plan->bindingLayout->parameterCount);
        self::assertNotEmpty($plan->fingerprint);
    }

    public function test_sqlite_dialect_compiles_sqg_operation_using_plan_artifact_fingerprint(): void
    {
        $builder = (new SelectQueryBuilder())
            ->from('users', 'u')
            ->select('u.id');
        $graph = $builder->getSQG();
        $caps = DatabaseCapabilitySet::minimalSet('sqlite');
        $certification = $graph->validate($caps);
        $optimization = (new NoOpQueryOptimizer())->optimize(new QueryOptimizationInput(
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
}
