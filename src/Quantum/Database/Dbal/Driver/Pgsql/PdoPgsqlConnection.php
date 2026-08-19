<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\Pgsql;

use Quantum\Database\Dbal\Driver\PdoCommon\AbstractPdoConnection;

final class PdoPgsqlConnection extends AbstractPdoConnection
{
    public function __construct(array $config)
    {
        parent::__construct(
            config: $config,
            mapper: new PgsqlExceptionMapper(),
            driverName: 'pgsql',
            quoteCharacter: '"',
            supportsSavepoints: true,
            paramStyle: 'positional_$n',
        );
    }

    protected function connectInternal(array $config): \PDO
    {
        $parts = [];
        foreach (['host','port','dbname','user','password','sslmode','application_name'] as $k) {
            if (isset($config[$k]) && $config[$k] !== '') {
                $parts[] = $k . '=' . $config[$k];
            }
        }
        $dsn = 'pgsql:' . implode(';', $parts);
        $pdo = new \PDO($dsn, options: (array)($config['options'] ?? []));
        return $pdo;
    }

    protected function onAfterConnect(\PDO $pdo): void
    {
        parent::onAfterConnect($pdo);
        $charset = $this->config['charset'] ?? 'UTF8';
        if ($charset !== null && $charset !== '') {
            $pdo->exec("SET NAMES '{$charset}'");
        }
        if (!empty($this->config['search_path'])) {
            $sp = $this->config['search_path'];
            $pdo->exec("SET search_path TO {$sp}");
        }
    }

    protected function sqlSetTxIsolation(string $levelValue): string
    {
        return "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$levelValue}";
    }

    protected function isConnectivity(\Throwable $t): bool
    {
        $m = strtolower($t->getMessage());
        return str_contains($m, 'could not connect') || str_contains($m, 'server closed') || str_contains($m, 'connection refused');
    }
    protected function isRetryableConnect(\Throwable $t): bool { return true; }
}
