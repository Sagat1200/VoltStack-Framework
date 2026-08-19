<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

/**
 * 36 SemanticNodeKind del SQG (DDD-V1-03). Cada clase concreta SemanticNode implementa ::kind() con uno.
 * Se agrupan por categoría: ROOT / SOURCES / JOINS / PROJECTIONS / PREDICATES / EXPRESSIONS /
 * AGGREGATES / MODIFIERS / MUTATIONS.
 */
enum SemanticNodeKind: string
{
    // ---- ROOT ----
    case SelectStatement = 'select_statement';
    case InsertStatement = 'insert_statement';
    case UpdateStatement = 'update_statement';
    case DeleteStatement = 'delete_statement';

        // ---- SOURCES (FROM) ----
    case TableSource = 'table_source';
    case SubquerySource = 'subquery_source';
    case ValuesSource = 'values_source';
    case CteSource = 'cte_source';

        // ---- JOINS ----
    case InnerJoin = 'inner_join';
    case LeftJoin = 'left_join';
    case RightJoin = 'right_join';
    case FullJoin = 'full_join';
    case CrossJoin = 'cross_join';
    case LateralJoin = 'lateral_join';

        // ---- PROJECTIONS (SELECT list) ----
    case ProjectionList = 'projection_list';
    case AliasedProjection = 'aliased_projection';
    case StarProjection = 'star_projection';
    case QualifiedStarProjection = 'qualified_star_projection';

        // ---- PREDICATES (WHERE/HAVING) ----
    case PredicateAnd = 'predicate_and';
    case PredicateOr = 'predicate_or';
    case PredicateNot = 'predicate_not';
    case Comparison = 'comparison';
    case Between = 'between';
    case InList = 'in_list';
    case InSubquery = 'in_subquery';
    case Exists = 'exists';
    case IsNull = 'is_null';
    case IsDistinctFrom = 'is_distinct_from';

        // ---- EXPRESSIONS ----
    case ColumnReference = 'column_ref';
    case Parameter = 'parameter';
    case Literal = 'literal';
    case BinaryExpression = 'binary_expression';
    case UnaryExpression = 'unary_expression';
    case FunctionCall = 'function_call';
    case CaseExpression = 'case_expression';
    case CastExpression = 'cast_expression';
    case SubqueryExpression = 'subquery_expression';

        // ---- AGGREGATES / GROUPING ----
    case AggregateFunction = 'aggregate_function';
    case GroupByList = 'group_by_list';
    case HavingClause = 'having_clause';
    case WindowFunction = 'window_function';
    case WindowSpecification = 'window_spec';

        // ---- MODIFIERS (ORDER / LIMIT / OFFSET) ----
    case OrderByList = 'order_by_list';
    case OrderByItem = 'order_by_item';
    case LimitClause = 'limit_clause';
    case OffsetClause = 'offset_clause';
    case DistinctModifier = 'distinct_modifier';

        // ---- MUTATIONS (INSERT/UPDATE/DELETE data) ----
    case InsertValues = 'insert_values';
    case UpdateAssignment = 'update_assignment';
    case ReturningClause = 'returning_clause';
    case UpsertClause = 'upsert_clause';
    case CteList = 'cte_list';
}