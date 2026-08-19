<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Emitter\Dialects;

use Quantum\Database\Dialect\Emitter\NodeSqlEmitter;
use Quantum\Database\Operation\Sqg\Node\UpsertClauseNode;

/**
 * SQLite: quoting double-quote (funciona también), ? positional,
 *   ILike nativa desde 3.30, RETURNING desde 3.35, || concat, GROUP_CONCAT string aggregator.
 */
final class SqliteNodeSqlEmitter extends NodeSqlEmitter
{
    protected function emitGroupConcatFnName(): string { return 'GROUP_CONCAT'; }

    protected function upsertDoNothing(UpsertClauseNode $n): string
    {
        $target = $n->conflictTarget !== null
            ? '(' . implode(', ', array_map(fn($c) => $this->dialect->quoteIdentifier($c), $n->conflictTarget)) . ')'
            : '';
        return "ON CONFLICT {$target} DO NOTHING";
    }
}
