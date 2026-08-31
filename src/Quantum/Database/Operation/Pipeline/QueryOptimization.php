<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Pipeline;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Sqg\GraphCertification;
use Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode;
use Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode;
use Quantum\Database\Operation\Sqg\Node\GroupByListNode;
use Quantum\Database\Operation\Sqg\Node\HavingClauseNode;
use Quantum\Database\Operation\Sqg\Node\JoinNode;
use Quantum\Database\Operation\Sqg\Node\LiteralNode;
use Quantum\Database\Operation\Sqg\Node\OrderByItemNode;
use Quantum\Database\Operation\Sqg\Node\OrderByListNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\UnaryExpressionNode;
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

final class DefaultQueryOptimizer implements QueryOptimizerInterface
{
    public function optimize(QueryOptimizationInput $input, ?DatabaseContext $context = null): QueryOptimizationResult
    {
        $foldedGraph = $this->foldGraph($input->graph);
        $appliedRules = $foldedGraph !== $input->graph ? ['constant_folding_v1'] : [];
        $candidateId = $appliedRules !== [] ? 'candidate:constant_folding_v1' : 'candidate:no_op';
        $strategy = $appliedRules !== [] ? 'constant_folding_v1' : 'no_op';

        $candidate = new QueryOptimizationCandidate(
            id: $candidateId,
            graph: $foldedGraph,
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
            appliedRules: $appliedRules,
        );

        $decision = new QueryOptimizationDecision(
            strategy: $strategy,
            selectedCandidateId: $candidate->id,
            estimatedCost: $candidate->estimatedCost,
            metadata: [
                'request_id' => $context?->requestId,
                'reason' => $appliedRules !== [] ? 'constant_expressions_folded' : 'no_foldable_literals_found',
            ],
        );

        $trace = new QueryOptimizationTrace(
            appliedRules: $appliedRules,
            candidateSummaries: [
                [
                    'id' => $candidate->id,
                    'cost' => $candidate->estimatedCost,
                    'rules' => $candidate->appliedRules,
                ],
            ],
            notes: $appliedRules !== []
                ? ['Default optimizer folded constant literal expressions without changing query semantics.']
                : ['Default optimizer found no safe literal-only expressions to fold.'],
        );

        return new QueryOptimizationResult(
            graph: $foldedGraph,
            certification: $input->certification,
            selectedCandidate: $candidate,
            decision: $decision,
            trace: $trace,
        );
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
}