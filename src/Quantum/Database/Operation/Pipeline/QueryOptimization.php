<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Sqg\GraphCertification;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;

interface QueryOptimizerInterface
{
    public function optimize(QueryOptimizationInput $input, ?DatabaseContext $context = null): QueryOptimizationResult;
}

final readonly class QueryOptimizationInput
{
    /**
     * @param array<string, mixed> $hints
     * @param array<string, int|float|string|bool|null> $limits
     */
    public function __construct(
        public SemanticQueryGraph $graph,
        public GraphCertification $certification,
        public DatabaseCapabilitySet $capabilities,
        public ?array $schema = null,
        public ?array $stats = null,
        public array $hints = [],
        public array $limits = [],
    ) {}
}

final readonly class QueryOptimizationCandidate
{
    /**
     * @param array<string, float|int|string|bool|null> $costBreakdown
     * @param list<string> $appliedRules
     */
    public function __construct(
        public string $id,
        public SemanticQueryGraph $graph,
        public float $estimatedCost,
        public array $costBreakdown = [],
        public array $appliedRules = [],
    ) {}
}

final readonly class QueryOptimizationDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $strategy,
        public string $selectedCandidateId,
        public float $estimatedCost,
        public array $metadata = [],
    ) {}
}

final readonly class QueryOptimizationTrace
{
    /**
     * @param list<string> $appliedRules
     * @param list<array{id:string,cost:float,rules:list<string>}> $candidateSummaries
     * @param list<string> $notes
     */
    public function __construct(
        public array $appliedRules = [],
        public array $candidateSummaries = [],
        public array $notes = [],
    ) {}
}

final readonly class QueryOptimizationResult
{
    public function __construct(
        public SemanticQueryGraph $graph,
        public GraphCertification $certification,
        public QueryOptimizationCandidate $selectedCandidate,
        public QueryOptimizationDecision $decision,
        public QueryOptimizationTrace $trace,
    ) {}
}

final class NoOpQueryOptimizer implements QueryOptimizerInterface
{
    public function optimize(QueryOptimizationInput $input, ?DatabaseContext $context = null): QueryOptimizationResult
    {
        $candidate = new QueryOptimizationCandidate(
            id: 'candidate:no_op',
            graph: $input->graph,
            estimatedCost: 0.0,
            costBreakdown: [
                'cardinality_cost' => 0.0,
                'join_fanout_cost' => 0.0,
                'sort_cost' => 0.0,
                'materialization_cost' => 0.0,
                'function_volatility_penalty' => 0.0,
                'capability_penalty' => 0.0,
                'memory_risk_penalty' => 0.0,
            ],
            appliedRules: [],
        );

        $decision = new QueryOptimizationDecision(
            strategy: 'no_op',
            selectedCandidateId: $candidate->id,
            estimatedCost: $candidate->estimatedCost,
            metadata: [
                'request_id' => $context?->requestId,
                'reason' => 'optimizer_v1_bootstrap',
            ],
        );

        $trace = new QueryOptimizationTrace(
            appliedRules: [],
            candidateSummaries: [
                [
                    'id' => $candidate->id,
                    'cost' => $candidate->estimatedCost,
                    'rules' => $candidate->appliedRules,
                ],
            ],
            notes: [
                'No-op optimizer selected the certified graph unchanged.',
                'This bootstrap implementation establishes explicit optimizer artifacts and trace output.',
            ],
        );

        return new QueryOptimizationResult(
            graph: $input->graph,
            certification: $input->certification,
            selectedCandidate: $candidate,
            decision: $decision,
            trace: $trace,
        );
    }
}
