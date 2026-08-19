<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Emitter\Dialects;

use Quantum\Database\Dialect\Emitter\NodeSqlEmitter;

/**
 * PgSQL: quoteChar=double-quote, param=$N, || concat, ILike nativa, DISTINCT ON, RETURNING, ARRAY_AGG/STRING_AGG.
 */
final class PgsqlNodeSqlEmitter extends NodeSqlEmitter
{
}
