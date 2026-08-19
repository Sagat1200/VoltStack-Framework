<?php declare(strict_types=1);

namespace Quantum\Database\Query\Expression;

/**
 * ExpressionBuilder: helper fluent composable para Query Builder.
 *
 * Los métodos DEVUELVEN strings o CompositeExpression en formato "SQL-ish" con
 * parámetros nombrados :xxx. Las strings generadas NUNCA se envían al motor
 * directamente; SelectQueryBuilder las parsea y traduce a SQG node-classes con
 * ParameterNode bindeadas (seguridad). Usar inline literals via literal() SOLO
 * para expresiones constantes (ej: funciones, números, NOW()).
 *
 * @api
 */
final class ExpressionBuilder
{
    // ======================== COMPOSITES (AND / OR / NOT) ========================

    public function andX(string|CompositeExpression ...$parts): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_AND, $parts);
    }

    public function orX(string|CompositeExpression ...$parts): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_OR, $parts);
    }

    public function notX(string|CompositeExpression $part): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_NOT, [$part]);
    }

    // ======================== COMPARACIONES ========================

    public function eq(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' = ' . $this->autoValue($valueOrParam);
    }

    public function neq(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' <> ' . $this->autoValue($valueOrParam);
    }

    public function lt(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' < ' . $this->autoValue($valueOrParam);
    }

    public function lte(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' <= ' . $this->autoValue($valueOrParam);
    }

    public function gt(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' > ' . $this->autoValue($valueOrParam);
    }

    public function gte(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' >= ' . $this->autoValue($valueOrParam);
    }

    // ======================== LIKE / IN ========================

    public function like(string $expr, mixed $valueOrParam, ?string $escape = null): string
    {
        $s = $expr . ' LIKE ' . $this->autoValue($valueOrParam);
        if ($escape !== null) {
            $s .= ' ESCAPE ' . $this->literal($escape);
        }
        return $s;
    }

    public function notLike(string $expr, mixed $valueOrParam): string
    {
        return $expr . ' NOT LIKE ' . $this->autoValue($valueOrParam);
    }

    /**
     * @param string $expr
     * @param list<mixed> $valuesOrParams
     */
    public function in(string $expr, array $valuesOrParams): string
    {
        $parts = array_map($this->autoValue(...), $valuesOrParams);
        return $expr . ' IN (' . implode(', ', $parts) . ')';
    }

    /**
     * @param string $expr
     * @param list<mixed> $valuesOrParams
     */
    public function notIn(string $expr, array $valuesOrParams): string
    {
        $parts = array_map($this->autoValue(...), $valuesOrParams);
        return $expr . ' NOT IN (' . implode(', ', $parts) . ')';
    }

    // ======================== NULL / BETWEEN / EXISTS ========================

    public function isNull(string $expr): string
    {
        return $expr . ' IS NULL';
    }

    public function isNotNull(string $expr): string
    {
        return $expr . ' IS NOT NULL';
    }

    public function between(string $expr, mixed $min, mixed $max): string
    {
        return $expr . ' BETWEEN ' . $this->autoValue($min) . ' AND ' . $this->autoValue($max);
    }

    public function exists(string $subquerySqlOrAlias): string
    {
        return 'EXISTS (' . $subquerySqlOrAlias . ')';
    }

    // ======================== LITERAL (INLINE, SEGURO SOLO PARA CONSTANTES) =====

    /**
     * Quotea un valor literal inline. SOLO usar para constantes/strings fijos;
     * cualquier entrada de usuario debe ir via setParameter(':x', $v).
     */
    public function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $v = (string)$value;
        $escaped = str_replace("'", "''", $v);
        return "'{$escaped}'";
    }

    // ======================== AGGREGATES / FUNCTIONS ========================

    public function count(string $expr = '*', bool $distinct = false): string
    {
        $d = $distinct ? 'DISTINCT ' : '';
        return "COUNT({$d}{$expr})";
    }

    public function sum(string $expr, bool $distinct = false): string
    {
        $d = $distinct ? 'DISTINCT ' : '';
        return "SUM({$d}{$expr})";
    }

    public function avg(string $expr, bool $distinct = false): string
    {
        $d = $distinct ? 'DISTINCT ' : '';
        return "AVG({$d}{$expr})";
    }

    public function min(string $expr): string
    {
        return "MIN({$expr})";
    }

    public function max(string $expr): string
    {
        return "MAX({$expr})";
    }

    public function coalesce(string ...$exprs): string
    {
        return 'COALESCE(' . implode(', ', $exprs) . ')';
    }

    public function concat(string ...$exprs): string
    {
        return 'CONCAT(' . implode(', ', $exprs) . ')';
    }

    public function upper(string $expr): string
    {
        return "UPPER({$expr})";
    }

    public function lower(string $expr): string
    {
        return "LOWER({$expr})";
    }

    public function now(): string
    {
        return 'NOW()';
    }

    // ======================== HELPERS INTERNOS ===================================

    /**
     * Si valueOrParam es string con prefijo ':' → parámetro nombrado,
     * lo devuelve intacto. Si es null → literal NULL. Si número → literal.
     * Cualquier otro scalar → tratamiento por inline; en la práctica los
     * consumidores del EB usan setParameter(':x', $v) así que valueOrParam
     * casi siempre será un string con ':xxx'.
     */
    private function autoValue(mixed $valueOrParam): string
    {
        if (is_string($valueOrParam) && str_starts_with($valueOrParam, ':')) {
            return $valueOrParam;
        }
        return $this->literal($valueOrParam);
    }
}
