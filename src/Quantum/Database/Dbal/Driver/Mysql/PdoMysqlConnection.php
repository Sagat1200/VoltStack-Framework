<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Mysql;

use Quantum\Database\Dbal\Driver\PdoCommon\AbstractPdoConnection;

class PdoMysqlConnection extends AbstractPdoConnection
{
    public function __construct(array $config)
    {
        parent::__construct(
            config: $config,
            mapper: new MysqlExceptionMapper(),
            driverName: $this->driverNameInternal(),
            quoteCharacter: '`',
            supportsSavepoints: true,
            paramStyle: 'positional_q',
        );
    }

    protected function driverNameInternal(): string { return 'mysql'; }

    protected function connectInternal(array $config): \PDO
    {
        $dsn = $this->buildDsn($config);
        $username = (string)($config['user'] ?? $config['username'] ?? 'root');
        $password = (string)($config['pass'] ?? $config['password'] ?? '');
        $options = (array)($config['options'] ?? []);
        $pdo = new \PDO($dsn, $username, $password, $options);
        return $pdo;
    }

    protected function buildDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $db   = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        if (!empty($config['unix_socket'])) {
            $dsn .= ';unix_socket=' . $config['unix_socket'];
        }
        return $dsn;
    }

    protected function onAfterConnect(\PDO $pdo): void
    {
        parent::onAfterConnect($pdo);
        $tz = $this->config['timezone'] ?? null;
        if ($tz !== null && $tz !== '') {
            $pdo->exec("SET time_zone = '{$tz}'");
        }
    }

    protected function isConnectivity(\Throwable $t): bool
    {
        $m = strtolower($t->getMessage());
        return str_contains($m, 'connection refused')
            || str_contains($m, 'no such host')
            || str_contains($m, 'gone away')
            || str_contains($m, 'server has gone away')
            || str_contains($m, "can't connect");
    }
    protected function isRetryableConnect(\Throwable $t): bool { return true; }
}
