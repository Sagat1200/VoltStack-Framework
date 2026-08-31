<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dialect\Enum\JoinType as DialectJoinType;
use Quantum\Database\Operation\Sqg\GraphCertification;
use Quantum\Database\Operation\Sqg\Node\AggregateFunctionNode;
use Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode;
use Quantum\Database\Operation\Sqg\Node\BetweenPredicateNode;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\CaseExpressionNode;
use Quantum\Database\Operation\Sqg\Node\ColumnReferenceNode;
use Quantum\Database\Operation\Sqg\Node\CteListNode;
use Quantum\Database\Operation\Sqg\Node\ExistsPredicateNode;
use Quantum\Database\Operation\Sqg\Node\FunctionCallNode;
use Quantum\Database\Operation\Sqg\Node\GroupByListNode;
use Quantum\Database\Operation\Sqg\Node\HavingClauseNode;
use Quantum\Database\Operation\Sqg\Node\InSubqueryPredicateNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Operation\Sqg\Node\LimitClauseNode;
use Quantum\Database\Operation\Sqg\Node\OffsetClauseNode;
use Quantum\Database\Operation\Sqg\Node\OrderByItemNode;
use Quantum\Database\Operation\Sqg\Node\OrderByListNode;
use Quantum\Database\Operation\Sqg\Node\ParameterNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\SubqueryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\SubquerySourceNode;
use Quantum\Database\Operation\Sqg\Node\TableSourceNode;
use Quantum\Database\Operation\Sqg\Node\UnaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\WindowFunctionNode;
use Quantum\Database\Operation\Sqg\SemanticNode;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;
use Quantum\Database\Operation\Sqg\Enum\BinaryOperator;
use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Enum\UnaryOperator;

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

final readonly class QueryCostEvaluation
{
    /**
     * @param array<string, float|int> $costBreakdown
     * @param array<string, float|int> $metrics
     */
    public function __construct(
        public float $estimatedCost,
        public array $costBreakdown,
        public array $metrics,
    ) {}
}

final class DeterministicQueryCostModel
{
    /**
     * @param array<string, int|float|string|bool|null> $limits
     */
    public function evaluate(
        SemanticQueryGraph $graph,
        DatabaseCapabilitySet $capabilities,
        array $limits = [],
    ): QueryCostEvaluation {
        $metrics = $this->collectMetrics($graph);

        $cardinalityBase = 18.0
            + ($metrics['source_count'] * 10.0)
            + ($metrics['projection_count'] * 1.25)
            + ($metrics['aggregate_count'] * 4.0)
            + ($metrics['distinct_count'] * 6.0)
            + ($metrics['group_count'] * 8.0)
            + ($metrics['cte_count'] * 3.0);
        $selectivityCredit = ($metrics['filter_clause_count'] * 1.5)
            + ($metrics['join_predicate_count'] * 1.5)
            + ($metrics['equality_predicate_count'] * 4.0)
            + ($metrics['range_predicate_count'] * 2.5)
            + ($this->limitSelectivityCredit($metrics['smallest_limit']));

        $cardinalityCost = max(1.0, $cardinalityBase - $selectivityCredit);
        $joinFanoutCost = max(
            0.0,
            ($metrics['join_count'] * 15.0)
                + (max(0, $metrics['source_count'] - 1) * 1.5)
                + (max(0, $metrics['join_count'] - 1) * 4.0)
                - ($metrics['join_predicate_count'] * 0.75),
        );
        $sortCost = ($metrics['order_count'] * 12.0)
            + ($metrics['distinct_count'] * 6.0)
            + ($metrics['group_count'] * 6.0)
            + ($metrics['window_function_count'] * 4.0);
        $materializationCost = ($metrics['subquery_count'] * 14.0)
            + ($metrics['cte_count'] * 8.0)
            + ($metrics['offset_count'] * 4.0)
            + ($metrics['recursive_cte_count'] * 6.0);
        $functionVolatilityPenalty = ($metrics['mutable_function_count'] * 20.0)
            + ($metrics['function_call_count'] * 2.0)
            + ($metrics['aggregate_count'] * 3.0)
            + ($metrics['case_expression_count'] * 1.5)
            + ($metrics['window_function_count'] * 2.0);
        $capabilityPenalty = 0.0;
        if ($metrics['window_function_count'] > 0 && !$capabilities->windowFunctions) {
            $capabilityPenalty += $metrics['window_function_count'] * 25.0;
        }
        if ($metrics['recursive_cte_count'] > 0 && !$capabilities->cteRecursive) {
            $capabilityPenalty += $metrics['recursive_cte_count'] * 25.0;
        }
        $memoryRiskPenalty = ($metrics['node_count'] * 0.25)
            + ($metrics['binary_expression_count'] * 0.5)
            + ($metrics['subquery_count'] * 4.0)
            + ($metrics['order_count'] * 2.0)
            + ($metrics['offset_count'] * 2.0)
            + ($this->budgetPressurePenalty($metrics, $limits));

        $breakdown = [
            'cardinality_cost' => round($cardinalityCost, 2),
            'join_fanout_cost' => round($joinFanoutCost, 2),
            'sort_cost' => round($sortCost, 2),
            'materialization_cost' => round($materializationCost, 2),
            'function_volatility_penalty' => round($functionVolatilityPenalty, 2),
            'capability_penalty' => round($capabilityPenalty, 2),
            'memory_risk_penalty' => round($memoryRiskPenalty, 2),
        ];

        return new QueryCostEvaluation(
            estimatedCost: round((float) array_sum($breakdown), 2),
            costBreakdown: $breakdown,
            metrics: $metrics,
        );
    }

    /**
     * @return array<string, float|int>
     */
    private function collectMetrics(SemanticQueryGraph $graph): array
    {
        $metrics = [
            'node_count' => 0,
            'source_count' => 0,
            'join_count' => 0,
            'projection_count' => 0,
            'filter_clause_count' => 0,
            'join_predicate_count' => 0,
            'equality_predicate_count' => 0,
            'range_predicate_count' => 0,
            'group_count' => 0,
            'having_count' => 0,
            'order_count' => 0,
            'distinct_count' => 0,
            'limit_count' => 0,
            'offset_count' => 0,
            'smallest_limit' => 0,
            'largest_offset' => 0,
            'function_call_count' => 0,
            'mutable_function_count' => 0,
            'aggregate_count' => 0,
            'window_function_count' => 0,
            'case_expression_count' => 0,
            'subquery_count' => 0,
            'cte_count' => 0,
            'recursive_cte_count' => 0,
            'parameter_count' => count($graph->parameters),
            'binary_expression_count' => 0,
        ];

        $walk = function (SemanticNode $node) use (&$walk, &$metrics): void {
            $metrics['node_count']++;

            if ($node instanceof SelectStatementNode) {
                $metrics['source_count'] += count($node->fromSources);
                $metrics['projection_count'] += count($node->projections?->items ?? []);
                $metrics['filter_clause_count'] += $node->where !== null ? 1 : 0;
                $metrics['group_count'] += count($node->groupBy?->expressions ?? []);
                $metrics['having_count'] += $node->having !== null ? 1 : 0;
                $metrics['filter_clause_count'] += $node->having !== null ? 1 : 0;
                $metrics['order_count'] += count($node->orderBy?->items ?? []);
                $metrics['distinct_count'] += $node->distinct !== null ? 1 : 0;
                if ($node->limit !== null) {
                    $metrics['limit_count']++;
                    $limit = $node->limit->limit;
                    $metrics['smallest_limit'] = $metrics['smallest_limit'] === 0
                        ? $limit
                        : min($metrics['smallest_limit'], $limit);
                }
                if ($node->offset !== null) {
                    $metrics['offset_count']++;
                    $metrics['largest_offset'] = max($metrics['largest_offset'], $node->offset->offset);
                }
            }

            if ($node instanceof JoinNode) {
                $metrics['join_count']++;
                $metrics['join_predicate_count'] += $node->on !== null
                    ? $this->countConjunctivePredicates($node->on)
                    : 0;
            }

            if ($node instanceof BinaryExpressionNode) {
                $metrics['binary_expression_count']++;
                match ($node->op) {
                    BinaryOperator::Eq => $metrics['equality_predicate_count']++,
                    BinaryOperator::Lt,
                    BinaryOperator::Lte,
                    BinaryOperator::Gt,
                    BinaryOperator::Gte => $metrics['range_predicate_count']++,
                    default => null,
                };
            }

            if ($node instanceof BetweenPredicateNode) {
                $metrics['range_predicate_count']++;
            }

            if ($node instanceof FunctionCallNode) {
                $metrics['function_call_count']++;
                $metrics['mutable_function_count'] += $node->isMutable ? 1 : 0;
            }

            if ($node instanceof AggregateFunctionNode) {
                $metrics['aggregate_count']++;
            }

            if ($node instanceof WindowFunctionNode) {
                $metrics['window_function_count']++;
            }

            if ($node instanceof CaseExpressionNode) {
                $metrics['case_expression_count']++;
            }

            if ($node instanceof CteListNode) {
                $metrics['cte_count'] += count($node->ctes);
                $metrics['recursive_cte_count'] += $node->recursive ? 1 : 0;
            }

            if (
                $node instanceof SubquerySourceNode
                || $node instanceof SubqueryExpressionNode
                || $node instanceof ExistsPredicateNode
                || $node instanceof InSubqueryPredicateNode
            ) {
                $metrics['subquery_count']++;
            }

            if ($node instanceof ParameterNode) {
                $metrics['parameter_count']++;
            }

            foreach ($node->children() as $child) {
                if ($child instanceof SemanticNode) {
                    $walk($child);
                }
            }
        };

        $walk($graph->root);

        return $metrics;
    }

    private function limitSelectivityCredit(int|float $smallestLimit): float
    {
        if ($smallestLimit <= 0) {
            return 0.0;
        }

        return match (true) {
            $smallestLimit <= 1 => 10.0,
            $smallestLimit <= 10 => 8.0,
            $smallestLimit <= 50 => 5.0,
            $smallestLimit <= 100 => 3.0,
            default => 1.0,
        };
    }

    /**
     * @param array<string, float|int> $metrics
     * @param array<string, int|float|string|bool|null> $limits
     */
    private function budgetPressurePenalty(array $metrics, array $limits): float
    {
        $penalty = 0.0;

        $maxNodeCount = $limits['max_node_count'] ?? null;
        if (is_int($maxNodeCount) || is_float($maxNodeCount)) {
            $penalty += max(0.0, ((float) $metrics['node_count']) - (float) $maxNodeCount) * 0.5;
        }

        $maxJoinCount = $limits['max_join_count'] ?? null;
        if (is_int($maxJoinCount) || is_float($maxJoinCount)) {
            $penalty += max(0.0, ((float) $metrics['join_count']) - (float) $maxJoinCount) * 4.0;
        }

        $maxSubqueryCount = $limits['max_subquery_count'] ?? null;
        if (is_int($maxSubqueryCount) || is_float($maxSubqueryCount)) {
            $penalty += max(0.0, ((float) $metrics['subquery_count']) - (float) $maxSubqueryCount) * 6.0;
        }

        return $penalty;
    }

    private function countConjunctivePredicates(SemanticNode $node): int
    {
        if ($node instanceof BinaryExpressionNode && $node->op === BinaryOperator::AndAlso) {
            return $this->countConjunctivePredicates($node->left) + $this->countConjunctivePredicates($node->right);
        }

        return 1;
    }
}

final class NoOpQueryOptimizer implements QueryOptimizerInterface
{
    public function optimize(QueryOptimizationInput $input, ?DatabaseContext $context = null): QueryOptimizationResult
    {
        $costEvaluation = (new DeterministicQueryCostModel())->evaluate(
            graph: $input->graph,
            capabilities: $input->capabilities,
            limits: $input->limits,
        );

        $candidate = new QueryOptimizationCandidate(
            id: 'candidate:no_op',
            graph: $input->graph,
            estimatedCost: $costEvaluation->estimatedCost,
            costBreakdown: $costEvaluation->costBreakdown,
            appliedRules: [],
        );

        $decision = new QueryOptimizationDecision(
            strategy: 'no_op',
            selectedCandidateId: $candidate->id,
            estimatedCost: $candidate->estimatedCost,
            metadata: [
                'request_id' => $context?->requestId,
                'reason' => 'optimizer_v1_bootstrap',
                'cost_model_version' => 'deterministic_v1',
                'selected_breakdown' => $candidate->costBreakdown,
                'selected_metrics' => $costEvaluation->metrics,
            ],
        );

        $trace = new QueryOptimizationTrace(
            appliedRules: [],
            candidateSummaries: [
                [
                    'id' => $candidate->id,
                    'cost' => $candidate->estimatedCost,
                    'rules' => $candidate->appliedRules,
                    'breakdown' => $candidate->costBreakdown,
                    'metrics' => $costEvaluation->metrics,
                ],
            ],
            notes: [
                'No-op optimizer selected the certified graph unchanged.',
                'This bootstrap implementation establishes explicit optimizer artifacts and trace output.',
                'Costing uses deterministic structural heuristics and does not depend on runtime statistics.',
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

final class DefaultQueryOptimizer implements QueryOptimizerInterface
{
    public function optimize(QueryOptimizationInput $input, ?DatabaseContext $context = null): QueryOptimizationResult
    {
        $costModel = new DeterministicQueryCostModel();
        ['graph' => $optimizedGraph, 'applied_rules' => $optimizedRules] = $this->applySafeRulePasses($input->graph);
        $baselineEvaluation = $costModel->evaluate(
            graph: $input->graph,
            capabilities: $input->capabilities,
            limits: $input->limits,
        );
        $baselineCandidate = new QueryOptimizationCandidate(
            id: 'candidate:no_op',
            graph: $input->graph,
            estimatedCost: $baselineEvaluation->estimatedCost,
            costBreakdown: $baselineEvaluation->costBreakdown,
            appliedRules: [],
        );

        $candidateSummaries = [[
            'id' => $baselineCandidate->id,
            'cost' => $baselineCandidate->estimatedCost,
            'rules' => $baselineCandidate->appliedRules,
            'breakdown' => $baselineCandidate->costBreakdown,
            'metrics' => $baselineEvaluation->metrics,
        ]];

        $selectedCandidate = $baselineCandidate;
        $selectedMetrics = $baselineEvaluation->metrics;
        $selectedReason = 'no_foldable_literals_found';
        $appliedRules = [];

        if ($optimizedGraph !== $input->graph) {
            $optimizedEvaluation = $costModel->evaluate(
                graph: $optimizedGraph,
                capabilities: $input->capabilities,
                limits: $input->limits,
            );
            $optimizedCandidate = new QueryOptimizationCandidate(
                id: $this->candidateIdFromRules($optimizedRules),
                graph: $optimizedGraph,
                estimatedCost: $optimizedEvaluation->estimatedCost,
                costBreakdown: $optimizedEvaluation->costBreakdown,
                appliedRules: $optimizedRules,
            );

            $candidateSummaries[] = [
                'id' => $optimizedCandidate->id,
                'cost' => $optimizedCandidate->estimatedCost,
                'rules' => $optimizedCandidate->appliedRules,
                'breakdown' => $optimizedCandidate->costBreakdown,
                'metrics' => $optimizedEvaluation->metrics,
            ];

            if ($optimizedCandidate->estimatedCost <= $baselineCandidate->estimatedCost) {
                $selectedCandidate = $optimizedCandidate;
                $selectedMetrics = $optimizedEvaluation->metrics;
                $selectedReason = $this->reasonFromRules($optimizedRules);
                $appliedRules = $optimizedCandidate->appliedRules;
            }
        }

        $strategy = $this->strategyFromRules($appliedRules);

        $decision = new QueryOptimizationDecision(
            strategy: $strategy,
            selectedCandidateId: $selectedCandidate->id,
            estimatedCost: $selectedCandidate->estimatedCost,
            metadata: [
                'request_id' => $context?->requestId,
                'reason' => $selectedReason,
                'cost_model_version' => 'deterministic_v1',
                'candidate_count' => count($candidateSummaries),
                'selected_rules' => $appliedRules,
                'selected_breakdown' => $selectedCandidate->costBreakdown,
                'selected_metrics' => $selectedMetrics,
                'cost_delta_vs_baseline' => round(
                    max(0.0, $baselineCandidate->estimatedCost - $selectedCandidate->estimatedCost),
                    2,
                ),
            ],
        );

        $trace = new QueryOptimizationTrace(
            appliedRules: $appliedRules,
            candidateSummaries: $candidateSummaries,
            notes: $appliedRules !== []
                ? [
                    'Default optimizer applied safe SQG rewrites without changing query semantics.',
                    'Candidate selection uses deterministic structural heuristics and chooses the lowest-cost candidate.',
                ]
                : [
                    'Default optimizer found no safe literal-only expressions to fold.',
                    'Costing uses deterministic structural heuristics and falls back to the certified graph when no cheaper candidate exists.',
                ],
        );

        return new QueryOptimizationResult(
            graph: $selectedCandidate->graph,
            certification: $input->certification,
            selectedCandidate: $selectedCandidate,
            decision: $decision,
            trace: $trace,
        );
    }

    /**
     * @return array{graph:SemanticQueryGraph,applied_rules:list<string>}
     */
    private function applySafeRulePasses(SemanticQueryGraph $graph): array
    {
        $currentGraph = $graph;
        $appliedRules = [];

        $foldedGraph = $this->foldGraph($currentGraph);
        if ($foldedGraph !== $currentGraph) {
            $currentGraph = $foldedGraph;
            $appliedRules[] = 'constant_folding_v1';
        }

        $normalizedGraph = $this->simplifyLimitOffsetGraph($currentGraph);
        if ($normalizedGraph !== $currentGraph) {
            $currentGraph = $normalizedGraph;
            $appliedRules[] = 'limit_offset_simplification_v1';
        }

        $canonicalPredicateGraph = $this->canonicalizePredicateGraph($currentGraph);
        if ($canonicalPredicateGraph !== $currentGraph) {
            $currentGraph = $canonicalPredicateGraph;
            $appliedRules[] = 'predicate_normalization_v1';
        }

        $booleanNormalizationGraph = $this->normalizeBooleanPredicateGraph($currentGraph);
        if ($booleanNormalizationGraph !== $currentGraph) {
            $currentGraph = $booleanNormalizationGraph;
            $appliedRules[] = 'boolean_predicate_normalization_v1';
        }

        $pushdownGraph = $this->pushDownWherePredicatesGraph($currentGraph);
        if ($pushdownGraph !== $currentGraph) {
            $currentGraph = $pushdownGraph;
            $appliedRules[] = 'predicate_pushdown_v1';
        }

        $reorderedJoinGraph = $this->reorderInnerJoinGraph($currentGraph);
        if ($reorderedJoinGraph !== $currentGraph) {
            $currentGraph = $reorderedJoinGraph;
            $appliedRules[] = 'join_reorder_v1';
        }

        return [
            'graph' => $currentGraph,
            'applied_rules' => $appliedRules,
        ];
    }

    private function foldGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if ($root instanceof SelectStatementNode) {
            $foldedRoot = $this->foldSelectStatement($root);
            if ($foldedRoot !== $root) {
                return new SemanticQueryGraph(root: $foldedRoot, parameters: $graph->parameters);
            }
        }

        return $graph;
    }

    private function simplifyLimitOffsetGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if (!$root instanceof SelectStatementNode) {
            return $graph;
        }

        $normalizedRoot = $this->simplifyLimitOffsetSelectStatement($root);
        if ($normalizedRoot === $root) {
            return $graph;
        }

        return new SemanticQueryGraph(root: $normalizedRoot, parameters: $graph->parameters);
    }

    private function pushDownWherePredicatesGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if (!$root instanceof SelectStatementNode) {
            return $graph;
        }

        $pushedDownRoot = $this->pushDownWherePredicatesSelectStatement($root);
        if ($pushedDownRoot === $root) {
            return $graph;
        }

        return new SemanticQueryGraph(root: $pushedDownRoot, parameters: $graph->parameters);
    }

    private function canonicalizePredicateGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if (!$root instanceof SelectStatementNode) {
            return $graph;
        }

        $canonicalRoot = $this->canonicalizePredicateSelectStatement($root);
        if ($canonicalRoot === $root) {
            return $graph;
        }

        return new SemanticQueryGraph(root: $canonicalRoot, parameters: $graph->parameters);
    }

    private function normalizeBooleanPredicateGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if (!$root instanceof SelectStatementNode) {
            return $graph;
        }

        $normalizedRoot = $this->normalizeBooleanPredicateSelectStatement($root);
        if ($normalizedRoot === $root) {
            return $graph;
        }

        return new SemanticQueryGraph(root: $normalizedRoot, parameters: $graph->parameters);
    }

    private function reorderInnerJoinGraph(SemanticQueryGraph $graph): SemanticQueryGraph
    {
        $root = $graph->root;
        if (!$root instanceof SelectStatementNode) {
            return $graph;
        }

        $reorderedRoot = $this->reorderInnerJoinSelectStatement($root);
        if ($reorderedRoot === $root) {
            return $graph;
        }

        return new SemanticQueryGraph(root: $reorderedRoot, parameters: $graph->parameters);
    }

    private function foldSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        $changed = false;

        $projections = $node->projections !== null
            ? $this->foldProjectionList($node->projections, $changed)
            : null;
        $joins = [];
        foreach ($node->joins as $join) {
            if ($join instanceof JoinNode) {
                $joins[] = $this->foldJoinNode($join, $changed);
            } else {
                $joins[] = $join;
            }
        }
        $where = $node->where !== null
            ? $this->foldExpression($node->where, $changed)
            : null;
        $groupBy = $node->groupBy !== null
            ? $this->foldGroupByList($node->groupBy, $changed)
            : null;
        $having = $node->having !== null
            ? $this->foldHavingClause($node->having, $changed)
            : null;
        $orderBy = $node->orderBy !== null
            ? $this->foldOrderByList($node->orderBy, $changed)
            : null;

        if (!$changed) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $projections,
            fromSources: $node->fromSources,
            joins: $joins,
            where: $where,
            groupBy: $groupBy,
            having: $having,
            orderBy: $orderBy,
            limit: $node->limit,
            offset: $node->offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function simplifyLimitOffsetSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        $changed = false;
        $limit = $node->limit;
        $offset = $node->offset;

        if ($offset instanceof OffsetClauseNode && $offset->offset <= 0) {
            $offset = null;
            $changed = true;
        }

        if ($limit instanceof LimitClauseNode && $limit->limit === 0 && $offset instanceof OffsetClauseNode) {
            $offset = null;
            $changed = true;
        }

        if (!$changed) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $node->projections,
            fromSources: $node->fromSources,
            joins: $node->joins,
            where: $node->where,
            groupBy: $node->groupBy,
            having: $node->having,
            orderBy: $node->orderBy,
            limit: $limit,
            offset: $offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function pushDownWherePredicatesSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        if ($node->where === null || $node->joins === []) {
            return $node;
        }

        $remainingPredicates = $this->flattenConjunctivePredicates($node->where);
        $joins = [];
        $changed = false;
        $visibleAliases = $this->collectVisibleSourceAliases($node->fromSources);

        foreach ($node->joins as $join) {
            if (!$join instanceof JoinNode) {
                $joins[] = $join;
                continue;
            }

            $currentJoin = $join;
            $joinAlias = $this->resolveSourceAlias($join->right);

            if ($joinAlias !== null && $join->type === DialectJoinType::Inner) {
                $visibleWithCurrent = $visibleAliases;
                $visibleWithCurrent[$joinAlias] = true;

                $pushable = [];
                $stillPending = [];

                foreach ($remainingPredicates as $predicate) {
                    $aliases = $this->collectReferencedAliases($predicate);
                    if (
                        $aliases === null
                        || $aliases === []
                        || !isset($aliases[$joinAlias])
                        || array_diff_key($aliases, $visibleWithCurrent) !== []
                    ) {
                        $stillPending[] = $predicate;
                        continue;
                    }

                    $pushable[] = $predicate;
                }

                if ($pushable !== []) {
                    $currentJoin = $this->withInferredTypeIfPresent(new JoinNode(
                        type: $join->type,
                        right: $join->right,
                        on: $this->appendPredicatesToJoinOn($join->on, $pushable),
                        id: $join->id(),
                        flags: $join->flags(),
                        span: $join->sourceSpan(),
                    ), $join);
                    $remainingPredicates = $stillPending;
                    $changed = true;
                }
            }

            if ($joinAlias !== null) {
                $visibleAliases[$joinAlias] = true;
            }

            $joins[] = $currentJoin;
        }

        if (!$changed) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $node->projections,
            fromSources: $node->fromSources,
            joins: $joins,
            where: $this->buildConjunctivePredicate($remainingPredicates),
            groupBy: $node->groupBy,
            having: $node->having,
            orderBy: $node->orderBy,
            limit: $node->limit,
            offset: $node->offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function canonicalizePredicateSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        $changed = false;
        $joins = [];

        foreach ($node->joins as $join) {
            if (!$join instanceof JoinNode) {
                $joins[] = $join;
                continue;
            }

            $normalizedOn = $join->on !== null
                ? $this->canonicalizePredicateExpression($join->on, $changed)
                : null;

            $joins[] = $normalizedOn !== $join->on
                ? $this->withInferredTypeIfPresent(new JoinNode(
                    type: $join->type,
                    right: $join->right,
                    on: $normalizedOn,
                    id: $join->id(),
                    flags: $join->flags(),
                    span: $join->sourceSpan(),
                ), $join)
                : $join;
        }

        $where = $node->where !== null
            ? $this->canonicalizePredicateExpression($node->where, $changed)
            : null;
        $having = $node->having !== null
            ? $this->canonicalizeHavingClause($node->having, $changed)
            : null;

        if (!$changed) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $node->projections,
            fromSources: $node->fromSources,
            joins: $joins,
            where: $where,
            groupBy: $node->groupBy,
            having: $having,
            orderBy: $node->orderBy,
            limit: $node->limit,
            offset: $node->offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function normalizeBooleanPredicateSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        $changed = false;
        $joins = [];

        foreach ($node->joins as $join) {
            if (!$join instanceof JoinNode) {
                $joins[] = $join;
                continue;
            }

            $normalizedOn = $join->on !== null
                ? $this->normalizeBooleanPredicateExpression($join->on, $changed)
                : null;

            $joins[] = $normalizedOn !== $join->on
                ? $this->withInferredTypeIfPresent(new JoinNode(
                    type: $join->type,
                    right: $join->right,
                    on: $normalizedOn,
                    id: $join->id(),
                    flags: $join->flags(),
                    span: $join->sourceSpan(),
                ), $join)
                : $join;
        }

        $where = $node->where !== null
            ? $this->normalizeBooleanPredicateExpression($node->where, $changed)
            : null;
        $having = $node->having !== null
            ? $this->normalizeBooleanHavingClause($node->having, $changed)
            : null;

        if (!$changed) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $node->projections,
            fromSources: $node->fromSources,
            joins: $joins,
            where: $where,
            groupBy: $node->groupBy,
            having: $having,
            orderBy: $node->orderBy,
            limit: $node->limit,
            offset: $node->offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function reorderInnerJoinSelectStatement(SelectStatementNode $node): SelectStatementNode
    {
        if (
            count($node->fromSources) !== 1
            || count($node->joins) !== 1
            || !$node->fromSources[0] instanceof TableSourceNode
            || !$node->joins[0] instanceof JoinNode
            || $node->joins[0]->type !== DialectJoinType::Inner
            || !$node->joins[0]->right instanceof TableSourceNode
        ) {
            return $node;
        }

        /** @var TableSourceNode $baseSource */
        $baseSource = $node->fromSources[0];
        /** @var JoinNode $join */
        $join = $node->joins[0];
        /** @var TableSourceNode $joinedSource */
        $joinedSource = $join->right;

        $baseAlias = $baseSource->aliasOrName();
        $joinedAlias = $joinedSource->aliasOrName();

        $baseScore = $this->scoreLeadSourceEvidence($node, $baseAlias, $join->on);
        $joinedScore = $this->scoreLeadSourceEvidence($node, $joinedAlias, $join->on);

        if ($joinedScore <= $baseScore) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new SelectStatementNode(
            with: $node->with,
            distinct: $node->distinct,
            projections: $node->projections,
            fromSources: [$joinedSource],
            joins: [
                $this->withInferredTypeIfPresent(new JoinNode(
                    type: $join->type,
                    right: $baseSource,
                    on: $join->on,
                    id: $join->id(),
                    flags: $join->flags(),
                    span: $join->sourceSpan(),
                ), $join),
            ],
            where: $node->where,
            groupBy: $node->groupBy,
            having: $node->having,
            orderBy: $node->orderBy,
            limit: $node->limit,
            offset: $node->offset,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldProjectionList(ProjectionListNode $node, bool &$changed): ProjectionListNode
    {
        $items = [];
        $localChanged = false;
        foreach ($node->items as $item) {
            if ($item instanceof AliasedProjectionNode) {
                $foldedExpression = $this->foldExpression($item->expression, $localChanged);
                $items[] = $foldedExpression !== $item->expression
                    ? $this->withInferredTypeIfPresent(new AliasedProjectionNode(
                        expression: $foldedExpression,
                        alias: $item->alias,
                        id: $item->id(),
                        flags: $item->flags(),
                        span: $item->sourceSpan(),
                    ), $item)
                    : $item;
                continue;
            }

            if ($item instanceof SemanticNode) {
                $items[] = $this->foldExpression($item, $localChanged);
                continue;
            }

            $items[] = $item;
        }

        if (!$localChanged) {
            return $node;
        }

        $changed = true;

        return $this->withInferredTypeIfPresent(new ProjectionListNode(
            items: $items,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldJoinNode(JoinNode $node, bool &$changed): JoinNode
    {
        $on = $node->on !== null ? $this->foldExpression($node->on, $changed) : null;
        if ($on === $node->on) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new JoinNode(
            type: $node->type,
            right: $node->right,
            on: $on,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldGroupByList(GroupByListNode $node, bool &$changed): GroupByListNode
    {
        $expressions = [];
        $localChanged = false;
        foreach ($node->expressions as $expression) {
            $expressions[] = $this->foldExpression($expression, $localChanged);
        }

        if (!$localChanged) {
            return $node;
        }

        $changed = true;

        return $this->withInferredTypeIfPresent(new GroupByListNode(
            expressions: $expressions,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldHavingClause(HavingClauseNode $node, bool &$changed): HavingClauseNode
    {
        $predicate = $this->foldExpression($node->predicate, $changed);
        if ($predicate === $node->predicate) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new HavingClauseNode(
            predicate: $predicate,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function canonicalizeHavingClause(HavingClauseNode $node, bool &$changed): HavingClauseNode
    {
        $predicate = $this->canonicalizePredicateExpression($node->predicate, $changed);
        if ($predicate === $node->predicate) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new HavingClauseNode(
            predicate: $predicate,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function normalizeBooleanHavingClause(HavingClauseNode $node, bool &$changed): HavingClauseNode
    {
        $predicate = $this->normalizeBooleanPredicateExpression($node->predicate, $changed);
        if ($predicate === $node->predicate) {
            return $node;
        }

        return $this->withInferredTypeIfPresent(new HavingClauseNode(
            predicate: $predicate,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldOrderByList(OrderByListNode $node, bool &$changed): OrderByListNode
    {
        $items = [];
        $localChanged = false;
        foreach ($node->items as $item) {
            $expression = $this->foldExpression($item->expression, $localChanged);
            $items[] = $expression !== $item->expression
                ? $this->withInferredTypeIfPresent(new OrderByItemNode(
                    expression: $expression,
                    direction: $item->direction,
                    nulls: $item->nulls,
                    id: $item->id(),
                    flags: $item->flags(),
                    span: $item->sourceSpan(),
                ), $item)
                : $item;
        }

        if (!$localChanged) {
            return $node;
        }

        $changed = true;

        return $this->withInferredTypeIfPresent(new OrderByListNode(
            items: $items,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function foldExpression(SemanticNode $node, bool &$changed): SemanticNode
    {
        if ($node instanceof BinaryExpressionNode) {
            $left = $this->foldExpression($node->left, $changed);
            $right = $this->foldExpression($node->right, $changed);
            $current = $node;

            if ($left !== $node->left || $right !== $node->right) {
                $current = $this->withInferredTypeIfPresent(new BinaryExpressionNode(
                    op: $node->op,
                    left: $left,
                    right: $right,
                    id: $node->id(),
                    flags: $node->flags(),
                    span: $node->sourceSpan(),
                ), $node);
            }

            $folded = $this->tryFoldBinary($current);
            if ($folded !== $current) {
                $changed = true;
                return $folded;
            }

            return $current;
        }

        if ($node instanceof UnaryExpressionNode) {
            $operand = $this->foldExpression($node->operand, $changed);
            $current = $node;

            if ($operand !== $node->operand) {
                $current = $this->withInferredTypeIfPresent(new UnaryExpressionNode(
                    op: $node->op,
                    operand: $operand,
                    id: $node->id(),
                    flags: $node->flags(),
                    span: $node->sourceSpan(),
                ), $node);
            }

            $folded = $this->tryFoldUnary($current);
            if ($folded !== $current) {
                $changed = true;
                return $folded;
            }

            return $current;
        }

        return $node;
    }

    private function canonicalizePredicateExpression(SemanticNode $node, bool &$changed): SemanticNode
    {
        if ($node instanceof BinaryExpressionNode) {
            $left = $this->canonicalizePredicateExpression($node->left, $changed);
            $right = $this->canonicalizePredicateExpression($node->right, $changed);
            $current = $node;

            if ($left !== $node->left || $right !== $node->right) {
                $current = $this->withInferredTypeIfPresent(new BinaryExpressionNode(
                    op: $node->op,
                    left: $left,
                    right: $right,
                    id: $node->id(),
                    flags: $node->flags(),
                    span: $node->sourceSpan(),
                ), $node);
            }

            $canonicalized = $this->canonicalizeComparisonBinary($current);
            if ($canonicalized !== $current) {
                $changed = true;
                return $canonicalized;
            }

            return $current;
        }

        if ($node instanceof UnaryExpressionNode) {
            $operand = $this->canonicalizePredicateExpression($node->operand, $changed);
            if ($operand === $node->operand) {
                return $node;
            }

            $changed = true;

            return $this->withInferredTypeIfPresent(new UnaryExpressionNode(
                op: $node->op,
                operand: $operand,
                id: $node->id(),
                flags: $node->flags(),
                span: $node->sourceSpan(),
            ), $node);
        }

        return $node;
    }

    private function normalizeBooleanPredicateExpression(SemanticNode $node, bool &$changed): SemanticNode
    {
        if ($node instanceof BinaryExpressionNode) {
            $left = $this->normalizeBooleanPredicateExpression($node->left, $changed);
            $right = $this->normalizeBooleanPredicateExpression($node->right, $changed);
            $current = $node;

            if ($left !== $node->left || $right !== $node->right) {
                $current = $this->withInferredTypeIfPresent(new BinaryExpressionNode(
                    op: $node->op,
                    left: $left,
                    right: $right,
                    id: $node->id(),
                    flags: $node->flags(),
                    span: $node->sourceSpan(),
                ), $node);
            }

            if (in_array($current->op, [BinaryOperator::AndAlso, BinaryOperator::OrElse], true)) {
                $normalized = $this->normalizeAssociativeBooleanBinary($current);
                if ($normalized !== $current) {
                    $changed = true;
                    return $normalized;
                }
            }

            return $current;
        }

        if ($node instanceof UnaryExpressionNode) {
            $operand = $this->normalizeBooleanPredicateExpression($node->operand, $changed);
            if ($operand === $node->operand) {
                return $node;
            }

            $changed = true;

            return $this->withInferredTypeIfPresent(new UnaryExpressionNode(
                op: $node->op,
                operand: $operand,
                id: $node->id(),
                flags: $node->flags(),
                span: $node->sourceSpan(),
            ), $node);
        }

        return $node;
    }

    private function tryFoldBinary(BinaryExpressionNode $node): SemanticNode
    {
        if (!$node->left instanceof LiteralNode || !$node->right instanceof LiteralNode) {
            return $node;
        }

        $left = $node->left->value;
        $right = $node->right->value;
        $value = null;

        switch ($node->op) {
            case BinaryOperator::Plus:
                if (!$this->isNumericLiteralValue($left) || !$this->isNumericLiteralValue($right)) {
                    return $node;
                }
                $value = $left + $right;
                break;
            case BinaryOperator::Minus:
                if (!$this->isNumericLiteralValue($left) || !$this->isNumericLiteralValue($right)) {
                    return $node;
                }
                $value = $left - $right;
                break;
            case BinaryOperator::Star:
                if (!$this->isNumericLiteralValue($left) || !$this->isNumericLiteralValue($right)) {
                    return $node;
                }
                $value = $left * $right;
                break;
            case BinaryOperator::Slash:
                if (
                    !$this->isNumericLiteralValue($left)
                    || !$this->isNumericLiteralValue($right)
                    || (float) $right === 0.0
                ) {
                    return $node;
                }
                $value = $left / $right;
                break;
            case BinaryOperator::Percent:
                if (
                    !is_int($left)
                    || !is_int($right)
                    || $right === 0
                ) {
                    return $node;
                }
                $value = $left % $right;
                break;
            case BinaryOperator::Concat:
                if ($left === null || $right === null) {
                    return $node;
                }
                $value = (string) $left . (string) $right;
                break;
            case BinaryOperator::AndAlso:
                if (!is_bool($left) || !is_bool($right)) {
                    return $node;
                }
                $value = $left && $right;
                break;
            case BinaryOperator::OrElse:
                if (!is_bool($left) || !is_bool($right)) {
                    return $node;
                }
                $value = $left || $right;
                break;
            case BinaryOperator::Eq:
                if ($left === null || $right === null) {
                    return $node;
                }
                $value = $left === $right;
                break;
            case BinaryOperator::NotEq:
                if ($left === null || $right === null) {
                    return $node;
                }
                $value = $left !== $right;
                break;
            case BinaryOperator::Lt:
                if (!$this->canCompareLiteralValues($left, $right)) {
                    return $node;
                }
                $value = $left < $right;
                break;
            case BinaryOperator::Lte:
                if (!$this->canCompareLiteralValues($left, $right)) {
                    return $node;
                }
                $value = $left <= $right;
                break;
            case BinaryOperator::Gt:
                if (!$this->canCompareLiteralValues($left, $right)) {
                    return $node;
                }
                $value = $left > $right;
                break;
            case BinaryOperator::Gte:
                if (!$this->canCompareLiteralValues($left, $right)) {
                    return $node;
                }
                $value = $left >= $right;
                break;
            default:
                return $node;
        }

        return $this->withInferredTypeIfPresent(new LiteralNode(
            value: $value,
            declaredType: $this->inferLiteralType($value),
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function canonicalizeComparisonBinary(BinaryExpressionNode $node): BinaryExpressionNode
    {
        if (!in_array($node->op, [
            BinaryOperator::Eq,
            BinaryOperator::NotEq,
            BinaryOperator::Lt,
            BinaryOperator::Lte,
            BinaryOperator::Gt,
            BinaryOperator::Gte,
        ], true)) {
            return $node;
        }

        if ($this->isCanonicalComparisonOrientation($node->left, $node->right, $node->op)) {
            return $node;
        }

        $swappedOperator = $this->swapComparisonOperator($node->op);

        return $this->withInferredTypeIfPresent(new BinaryExpressionNode(
            op: $swappedOperator,
            left: $node->right,
            right: $node->left,
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function normalizeAssociativeBooleanBinary(BinaryExpressionNode $node): BinaryExpressionNode
    {
        $parts = $this->flattenAssociativeBooleanBinary($node, $node->op);
        usort(
            $parts,
            fn(SemanticNode $left, SemanticNode $right): int => strcmp(
                $this->semanticNodeSortKey($left),
                $this->semanticNodeSortKey($right),
            ),
        );

        $normalized = $parts[0];
        for ($i = 1, $max = count($parts); $i < $max; $i++) {
            $normalized = new BinaryExpressionNode(
                left: $normalized,
                right: $parts[$i],
                op: $node->op,
            );
        }

        return $normalized instanceof BinaryExpressionNode
            ? $this->withInferredTypeIfPresent($normalized, $node)
            : $node;
    }

    private function tryFoldUnary(UnaryExpressionNode $node): SemanticNode
    {
        if (!$node->operand instanceof LiteralNode) {
            return $node;
        }

        $operand = $node->operand->value;
        $value = null;

        switch ($node->op) {
            case UnaryOperator::Not:
                if (!is_bool($operand)) {
                    return $node;
                }
                $value = !$operand;
                break;
            case UnaryOperator::Neg:
                if (!$this->isNumericLiteralValue($operand)) {
                    return $node;
                }
                $value = -$operand;
                break;
            case UnaryOperator::Pos:
                if (!$this->isNumericLiteralValue($operand)) {
                    return $node;
                }
                $value = +$operand;
                break;
            default:
                return $node;
        }

        return $this->withInferredTypeIfPresent(new LiteralNode(
            value: $value,
            declaredType: $this->inferLiteralType($value),
            id: $node->id(),
            flags: $node->flags(),
            span: $node->sourceSpan(),
        ), $node);
    }

    private function isNumericLiteralValue(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private function canCompareLiteralValues(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return (is_int($left) || is_float($left) || is_string($left))
            && (is_int($right) || is_float($right) || is_string($right));
    }

    private function inferLiteralType(mixed $value): DataType
    {
        return match (true) {
            is_bool($value) => DataType::Bool,
            is_int($value) => DataType::Int8,
            is_float($value) => DataType::Float8,
            is_string($value) => DataType::Text,
            default => DataType::Unknown,
        };
    }

    private function isCanonicalComparisonOrientation(
        SemanticNode $left,
        SemanticNode $right,
        BinaryOperator $operator,
    ): bool {
        if ($left instanceof ColumnReferenceNode && $this->isComparableScalarNode($right)) {
            return true;
        }

        if ($this->isComparableScalarNode($left) && $right instanceof ColumnReferenceNode) {
            return false;
        }

        if ($left instanceof ColumnReferenceNode && $right instanceof ColumnReferenceNode) {
            return $this->columnReferenceSortKey($left) <= $this->columnReferenceSortKey($right);
        }

        if (
            in_array($operator, [BinaryOperator::Lt, BinaryOperator::Lte, BinaryOperator::Gt, BinaryOperator::Gte], true)
            && $this->isComparableScalarNode($left)
            && $this->isComparableScalarNode($right)
        ) {
            return true;
        }

        return true;
    }

    private function swapComparisonOperator(BinaryOperator $operator): BinaryOperator
    {
        return match ($operator) {
            BinaryOperator::Lt => BinaryOperator::Gt,
            BinaryOperator::Lte => BinaryOperator::Gte,
            BinaryOperator::Gt => BinaryOperator::Lt,
            BinaryOperator::Gte => BinaryOperator::Lte,
            default => $operator,
        };
    }

    private function isComparableScalarNode(SemanticNode $node): bool
    {
        return $node instanceof LiteralNode || $node instanceof ParameterNode;
    }

    private function columnReferenceSortKey(ColumnReferenceNode $node): string
    {
        return ($node->tableAlias ?? '') . '.' . $node->column;
    }

    private function semanticNodeSortKey(SemanticNode $node): string
    {
        return match (true) {
            $node instanceof ColumnReferenceNode => 'column:' . $this->columnReferenceSortKey($node),
            $node instanceof LiteralNode => 'literal:' . json_encode($node->value),
            $node instanceof ParameterNode => 'param:' . ($node->name ?? (string) $node->index),
            $node instanceof BinaryExpressionNode => sprintf(
                'binary:%s:%s:%s',
                $node->op->value,
                $this->semanticNodeSortKey($node->left),
                $this->semanticNodeSortKey($node->right),
            ),
            $node instanceof UnaryExpressionNode => sprintf(
                'unary:%s:%s',
                $node->op->value,
                $this->semanticNodeSortKey($node->operand),
            ),
            default => $node->kind()->value . ':' . $node->id(),
        };
    }

    /**
     * @template T of SemanticNode
     * @param T $newNode
     * @param SemanticNode $sourceNode
     * @return T
     */
    private function withInferredTypeIfPresent(SemanticNode $newNode, SemanticNode $sourceNode): SemanticNode
    {
        $inferredType = $sourceNode->inferredType();

        return $inferredType !== null ? $newNode->withInferredType($inferredType) : $newNode;
    }

    /**
     * @param list<SemanticNode> $predicates
     */
    private function appendPredicatesToJoinOn(?SemanticNode $existing, array $predicates): SemanticNode
    {
        $parts = $existing instanceof SemanticNode ? [$existing] : [];
        array_push($parts, ...$predicates);

        return $this->buildConjunctivePredicate($parts) ?? throw new \RuntimeException('Join predicate pushdown requires at least one predicate.');
    }

    /**
     * @return list<SemanticNode>
     */
    private function flattenConjunctivePredicates(SemanticNode $node): array
    {
        if ($node instanceof BinaryExpressionNode && $node->op === BinaryOperator::AndAlso) {
            return [
                ...$this->flattenConjunctivePredicates($node->left),
                ...$this->flattenConjunctivePredicates($node->right),
            ];
        }

        return [$node];
    }

    /**
     * @param list<SemanticNode> $parts
     */
    private function buildConjunctivePredicate(array $parts): ?SemanticNode
    {
        if ($parts === []) {
            return null;
        }

        $predicate = $parts[0];
        for ($i = 1, $max = count($parts); $i < $max; $i++) {
            $predicate = new BinaryExpressionNode(
                left: $predicate,
                right: $parts[$i],
                op: BinaryOperator::AndAlso,
            );
        }

        return $predicate;
    }

    /**
     * @return list<SemanticNode>
     */
    private function flattenAssociativeBooleanBinary(SemanticNode $node, BinaryOperator $operator): array
    {
        if ($node instanceof BinaryExpressionNode && $node->op === $operator) {
            return [
                ...$this->flattenAssociativeBooleanBinary($node->left, $operator),
                ...$this->flattenAssociativeBooleanBinary($node->right, $operator),
            ];
        }

        return [$node];
    }

    /**
     * @param list<SemanticNode> $sources
     * @return array<string, bool>
     */
    private function collectVisibleSourceAliases(array $sources): array
    {
        $aliases = [];

        foreach ($sources as $source) {
            $alias = $this->resolveSourceAlias($source);
            if ($alias !== null) {
                $aliases[$alias] = true;
            }
        }

        return $aliases;
    }

    private function resolveSourceAlias(SemanticNode $source): ?string
    {
        return match (true) {
            $source instanceof TableSourceNode => $source->aliasOrName(),
            $source instanceof SubquerySourceNode => $source->alias,
            default => null,
        };
    }

    /**
     * @return array<string, bool>|null
     */
    private function collectReferencedAliases(SemanticNode $node): ?array
    {
        $aliases = [];
        $hasBareColumnReference = false;

        $walk = function (SemanticNode $current) use (&$walk, &$aliases, &$hasBareColumnReference): void {
            if ($current instanceof ColumnReferenceNode) {
                if ($current->tableAlias === null) {
                    $hasBareColumnReference = true;
                    return;
                }

                $aliases[$current->tableAlias] = true;
            }

            foreach ($current->children() as $child) {
                if ($child instanceof SemanticNode) {
                    $walk($child);
                }
            }
        };

        $walk($node);

        return $hasBareColumnReference ? null : $aliases;
    }

    private function scoreLeadSourceEvidence(
        SelectStatementNode $select,
        string $alias,
        ?SemanticNode $joinPredicate = null,
    ): int {
        $score = 0;

        foreach ($this->collectComparableEvidenceForAlias($select->where, $alias, 'where') as $item) {
            $score += $this->scoreComparableEvidence($item);
        }

        foreach ($this->collectComparableEvidenceForAlias($joinPredicate, $alias, 'join_on') as $item) {
            $score += $this->scoreComparableEvidence($item);
        }

        if ($select->orderBy !== null) {
            foreach ($select->orderBy->items as $item) {
                if ($item instanceof OrderByItemNode && $this->matchesOrderByAlias($item, $alias)) {
                    $score += 1;
                }
            }
        }

        return $score;
    }

    /**
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function collectComparableEvidenceForAlias(
        ?SemanticNode $node,
        string $alias,
        string $source,
    ): array {
        if (!$node instanceof SemanticNode) {
            return [];
        }

        $evidence = [];

        if ($node instanceof BinaryExpressionNode) {
            if (in_array($node->op, [
                BinaryOperator::Eq,
                BinaryOperator::Lt,
                BinaryOperator::Lte,
                BinaryOperator::Gt,
                BinaryOperator::Gte,
            ], true)) {
                $leftEvidence = $this->matchComparableEvidenceSide($node->left, $node->right, $alias, $source, $node->op);
                $rightEvidence = $this->matchComparableEvidenceSide($node->right, $node->left, $alias, $source, $node->op);
                $evidence = [...$evidence, ...$leftEvidence, ...$rightEvidence];
            }

            foreach ($node->children() as $child) {
                if ($child instanceof SemanticNode) {
                    $evidence = [...$evidence, ...$this->collectComparableEvidenceForAlias($child, $alias, $source)];
                }
            }
        }

        return $this->deduplicateComparableEvidence($evidence);
    }

    /**
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function matchComparableEvidenceSide(
        SemanticNode $candidateColumn,
        SemanticNode $otherSide,
        string $alias,
        string $source,
        BinaryOperator $operator,
    ): array {
        if (
            !$candidateColumn instanceof ColumnReferenceNode
            || !$this->matchesAlias($candidateColumn, $alias)
            || !$this->isJoinReorderComparableNode($otherSide)
        ) {
            return [];
        }

        return [[
            'source' => $source,
            'column' => $candidateColumn->column,
            'operator' => $operator->value,
            'comparable_kind' => $this->classifyComparableNodeForJoinReorder($otherSide),
        ]];
    }

    private function scoreComparableEvidence(array $item): int
    {
        return match ($item['operator']) {
            '=' => $item['comparable_kind'] === 'literal_or_param' ? 6 : 2,
            '<', '<=', '>', '>=' => $item['comparable_kind'] === 'literal_or_param' ? 4 : 1,
            default => 0,
        };
    }

    private function matchesOrderByAlias(OrderByItemNode $item, string $alias): bool
    {
        return $item->expression instanceof ColumnReferenceNode
            && $this->matchesAlias($item->expression, $alias);
    }

    private function matchesAlias(ColumnReferenceNode $column, string $alias): bool
    {
        return $column->tableAlias === null || $column->tableAlias === $alias;
    }

    private function isJoinReorderComparableNode(SemanticNode $node): bool
    {
        return $node instanceof LiteralNode || $node instanceof ParameterNode || $node instanceof ColumnReferenceNode;
    }

    private function classifyComparableNodeForJoinReorder(SemanticNode $node): string
    {
        return match (true) {
            $node instanceof LiteralNode, $node instanceof ParameterNode => 'literal_or_param',
            $node instanceof ColumnReferenceNode => 'column_ref',
            default => 'other',
        };
    }

    /**
     * @param list<array{source:string,column:string,operator:string,comparable_kind:string}> $evidence
     * @return list<array{source:string,column:string,operator:string,comparable_kind:string}>
     */
    private function deduplicateComparableEvidence(array $evidence): array
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

    /**
     * @param list<string> $rules
     */
    private function candidateIdFromRules(array $rules): string
    {
        return $rules === []
            ? 'candidate:no_op'
            : 'candidate:' . implode('+', $rules);
    }

    /**
     * @param list<string> $rules
     */
    private function strategyFromRules(array $rules): string
    {
        return match (count($rules)) {
            0 => 'no_op',
            1 => $rules[0],
            default => 'safe_rule_bundle_v1',
        };
    }

    /**
     * @param list<string> $rules
     */
    private function reasonFromRules(array $rules): string
    {
        if ($rules === []) {
            return 'no_safe_rewrite_applied';
        }

        if ($rules === ['constant_folding_v1']) {
            return 'constant_expressions_folded';
        }

        if ($rules === ['limit_offset_simplification_v1']) {
            return 'limit_offset_normalized';
        }

        if ($rules === ['predicate_pushdown_v1']) {
            return 'conjunctive_predicates_pushed_into_inner_join';
        }

        if ($rules === ['predicate_normalization_v1']) {
            return 'comparison_predicates_canonicalized';
        }

        if ($rules === ['boolean_predicate_normalization_v1']) {
            return 'boolean_predicate_tree_canonicalized';
        }

        if ($rules === ['join_reorder_v1']) {
            return 'inner_join_sources_reordered_for_selectivity';
        }

        return 'safe_rule_bundle_applied';
    }
}