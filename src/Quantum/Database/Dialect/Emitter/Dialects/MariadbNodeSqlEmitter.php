<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Emitter\Dialects;

/**
 * MariaDB hereda todo de MySQL por ahora; diferencias mínimas en V1.
 *   - RETURNING disponible desde MariaDB 10.5+
 *   - Window functions / CTE OK 10.2+.
 */
final class MariadbNodeSqlEmitter extends MysqlNodeSqlEmitter
{
}
