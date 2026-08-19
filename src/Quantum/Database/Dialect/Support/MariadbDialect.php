<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

/** MariaDB: mismo style que MySQL, pero puede soportar RETURNING 10.5+. Por ahora off por simplicidad V1. */
final class MariadbDialect extends AbstractDialect
{
    public function __construct()
    {
        parent::__construct(name: 'mariadb', quoteChar: '`', paramStyle: 'positional_q');
    }
}
