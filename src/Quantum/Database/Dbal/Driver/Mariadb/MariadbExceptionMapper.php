<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Mariadb;

use Quantum\Database\Dbal\Driver\Mysql\MysqlExceptionMapper;

/**
 * MariaDB 10.3+ mostly compatible con el comportamiento MySQL pero driverName=mariadb
 * y pequeños ajustes en exception mapping (Returning clause, etc.). V1 reusa MySQL.
 */
final class MariadbExceptionMapper extends MysqlExceptionMapper
{
}
