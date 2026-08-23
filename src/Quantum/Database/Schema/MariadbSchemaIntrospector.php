<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class MariadbSchemaIntrospector extends MysqlSchemaIntrospector
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }
}
