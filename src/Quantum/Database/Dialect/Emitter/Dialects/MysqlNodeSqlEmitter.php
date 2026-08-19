<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Emitter\Dialects;

use Quantum\Database\Dialect\Emitter\NodeSqlEmitter;
use Quantum\Database\Operation\Sqg\Node\IsDistinctFromNode;
use Quantum\Database\Operation\Sqg\Node\UpsertClauseNode;

/**
 * MySQL / MariaDB: backticks, ? positional, CONCAT(), no ILIKE (usa LOWER LIKE),
 *   no IS DISTINCT FROM (<=>), ON DUPLICATE KEY UPDATE en vez de ON CONFLICT.
 */
class MysqlNodeSqlEmitter extends NodeSqlEmitter
{
    protected function emitILikeOperator(): string { return 'LIKE'; }

    protected function emitConcatOperator(): string { return '/* CONCAT via FunctionCallNode */'; }

    protected function emitIsDistinctFrom(IsDistinctFromNode $n): string
    {
        $l = $this->node($n->left);
        $r = $this->node($n->right);
        // NOT <=>
        $op = $n->negated ? '<=>' : 'NOT <=>';
        // <=> devuelve 1 si son iguales incluyendo NULLs. Para IS DISTINCT: NO (<=>).
        return $n->negated ? "({$l} <=> {$r})" : "(NOT ({$l} <=> {$r}))";
    }

    protected function upsertDoNothing(UpsertClauseNode $n): string
    {
        return 'ON DUPLICATE KEY UPDATE ' . $this->dialect->quoteIdentifier(
            is_array($n->conflictTarget) && isset($n->conflictTarget[0]) ? $n->conflictTarget[0] : 'id'
        ) . '=' . $this->dialect->quoteIdentifier(
            is_array($n->conflictTarget) && isset($n->conflictTarget[0]) ? $n->conflictTarget[0] : 'id'
        );
    }

    protected function upsertDoUpdate(UpsertClauseNode $n): string
    {
        $set = $n->assignments !== null ? implode(', ', array_map($this->node(...), $n->assignments)) : '';
        if ($set === '') {
            // fallback: nada para actualizar; usamos do-nothing style.
            $c = is_array($n->conflictTarget) && isset($n->conflictTarget[0]) ? $n->conflictTarget[0] : 'id';
            $col = $this->dialect->quoteIdentifier($c);
            return "ON DUPLICATE KEY UPDATE {$col}={$col}";
        }
        return "ON DUPLICATE KEY UPDATE {$set}";
    }

    protected function emitGroupConcatFnName(): string { return 'GROUP_CONCAT'; }
}
