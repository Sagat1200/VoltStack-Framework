<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

final class MysqlDialect extends AbstractDialect
{
    public function __construct()
    {
        parent::__construct(name: 'mysql', quoteChar: '`', paramStyle: 'positional_q');
    }
}
