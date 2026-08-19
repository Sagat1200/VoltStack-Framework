<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Walker BFS/DFS sobre árbol SQG (implementación liviana V1).
 *
 * - pre-order: enterNode antes de children
 * - post-order: leaveNode después de children.
 */
final class NodeWalker
{
    public function walk(SemanticNode $root, NodeVisitor $v): void
    {
        $this->visit($root, $v);
    }

    private function visit(SemanticNode $node, NodeVisitor $v): void
    {
        $v->enterNode($node);
        // doble dispatch por familia
        $this->dispatchFamily($node, $v);
        foreach ($node->children() as $c) {
            $this->visit($c, $v);
        }
        $v->leaveNode($node);
    }

    private function dispatchFamily(SemanticNode $node, NodeVisitor $v): mixed
    {
        $k = $node->kind();
        return match(true) {
            self::in($k, ['select_statement','insert_statement','update_statement','delete_statement'])
                => $v->visitRoot($node),
            self::in($k, ['table_source','subquery_source','values_source','cte_source','cte_list'])
                => $v->visitSource($node),
            self::in($k, ['inner_join','left_join','right_join','full_join','cross_join','lateral_join'])
                => $v->visitJoin($node),
            self::in($k, ['projection_list','aliased_projection','star_projection','qualified_star_projection'])
                => $v->visitProjection($node),
            self::in($k, ['predicate_and','predicate_or','predicate_not','comparison','between','in_list','in_subquery','exists','is_null','is_distinct_from'])
                => $v->visitPredicate($node),
            self::in($k, ['column_ref','parameter','literal','binary_expression','unary_expression','function_call','case_expression','cast_expression','subquery_expression'])
                => $v->visitExpression($node),
            self::in($k, ['aggregate_function','group_by_list','having_clause','window_function','window_spec'])
                => $v->visitAggregate($node),
            self::in($k, ['order_by_list','order_by_item','limit_clause','offset_clause','distinct_modifier'])
                => $v->visitModifier($node),
            self::in($k, ['insert_values','update_assignment','returning_clause','upsert_clause'])
                => $v->visitMutation($node),
            default => null,
        };
    }

    private static function in(\BackedEnum $k, array $names): bool
    {
        return in_array($k->value, $names, true);
    }
}
