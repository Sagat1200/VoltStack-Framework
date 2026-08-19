<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Mariadb;

use Quantum\Database\Dbal\Driver\Mysql\PdoMysqlConnection;

final class PdoMariadbConnection extends PdoMysqlConnection
{
    protected function driverNameInternal(): string { return 'mariadb'; }
}
