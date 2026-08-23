<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Driver\Mariadb\PdoMariadbConnection;
use Quantum\Database\Dbal\Driver\Mysql\PdoMysqlConnection;
use Quantum\Database\Dbal\Driver\Pgsql\PdoPgsqlConnection;
use Quantum\Database\Schema\MariadbSchemaIntrospector;
use Quantum\Database\Schema\MysqlSchemaIntrospector;
use Quantum\Database\Schema\PgsqlSchemaIntrospector;
use Quantum\Database\Schema\SchemaIntrospectorInterface;

final class PortableSchemaIntrospectionIntegrationTest extends TestCase
{
    /**
     * @return array<string,array{driver:string,image:string,container_port:int}>
     */
    public static function driverProvider(): array
    {
        return [
            'pgsql' => ['pgsql', 'postgres:16-alpine', 5432],
            'mysql' => ['mysql', 'mysql:8.4', 3306],
            'mariadb' => ['mariadb', 'mariadb:11.4', 3306],
        ];
    }

    #[DataProvider('driverProvider')]
    public function test_live_driver_introspection_returns_portable_schema_metadata(
        string $driver,
        string $image,
        int $containerPort,
    ): void
    {
        $this->requireDockerDaemon();
        $this->requireDriverExtension($driver);

        $container = ExternalDatabaseContainer::boot(
            driver: $driver,
            image: $image,
            containerPort: $containerPort,
        );

        try {
            $connection = $this->makeConnection($driver, $container->hostPort);
            $this->waitUntilReady($connection, $driver);
            $this->installFixtureSchema($connection, $driver);

            $introspector = $this->makeIntrospector($driver, $connection);
            $users = $introspector->describeTable('vs_users');

            self::assertSame(['id'], $users->primaryKey);
            self::assertNotNull($users->column('id'));
            self::assertSame('bigint', $users->column('id')?->portableType);
            self::assertTrue($users->column('id')?->autoIncrement ?? false);
            self::assertSame('varchar', $users->column('email')?->portableType);
            self::assertSame(255, $users->column('email')?->length);
            self::assertSame('varchar', $users->column('status')?->portableType);
            self::assertSame('draft', $this->normalizeDefault($users->column('status')?->defaultValue));
            self::assertCount(2, $users->indexes);

            $emailIndex = $this->findIndex($users->indexes, 'email');
            self::assertNotNull($emailIndex);
            self::assertTrue($emailIndex->unique);

            self::assertCount(1, $users->foreignKeys);
            self::assertSame(['account_id'], $users->foreignKeys[0]->columns);
            self::assertSame('vs_accounts', $users->foreignKeys[0]->referencedTable);
            self::assertSame(['id'], $users->foreignKeys[0]->referencedColumns);
            self::assertSame('CASCADE', $users->foreignKeys[0]->onDelete);
        } finally {
            $container->shutdown();
        }
    }

    private function requireDockerDaemon(): void
    {
        $result = ExternalCommand::run('docker version --format "{{.Server.Version}}"');

        if ($result->exitCode !== 0 || trim($result->stdout) === '') {
            self::markTestSkipped('Docker daemon is not available for live schema integration tests.');
        }
    }

    private function requireDriverExtension(string $driver): void
    {
        $extension = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'mysql', 'mariadb' => 'pdo_mysql',
            default => null,
        };

        if ($extension !== null && !extension_loaded($extension)) {
            self::markTestSkipped(sprintf('Required PDO extension [%s] is not loaded.', $extension));
        }
    }

    private function makeConnection(string $driver, int $port): ConnectionInterface
    {
        return match ($driver) {
            'pgsql' => new PdoPgsqlConnection([
                'host' => '127.0.0.1',
                'port' => $port,
                'dbname' => 'voltstack',
                'database' => 'voltstack',
                'user' => 'voltstack',
                'password' => 'voltstack',
                'charset' => 'UTF8',
            ]),
            'mysql' => new PdoMysqlConnection([
                'host' => '127.0.0.1',
                'port' => $port,
                'database' => 'voltstack',
                'user' => 'root',
                'password' => 'voltstack',
                'charset' => 'utf8mb4',
            ]),
            'mariadb' => new PdoMariadbConnection([
                'host' => '127.0.0.1',
                'port' => $port,
                'database' => 'voltstack',
                'user' => 'root',
                'password' => 'voltstack',
                'charset' => 'utf8mb4',
            ]),
            default => throw new \InvalidArgumentException(sprintf('Unsupported driver [%s].', $driver)),
        };
    }

    private function waitUntilReady(ConnectionInterface $connection, string $driver): void
    {
        $deadline = microtime(true) + 45;
        $lastError = 'unknown';

        while (microtime(true) < $deadline) {
            try {
                $connection->connect();
                if ($driver === 'pgsql') {
                    $connection->executeQuery('SELECT 1');
                } else {
                    $connection->executeQuery('SELECT 1');
                }

                return;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                usleep(500_000);
            }
        }

        self::fail(sprintf('Container for driver [%s] was not ready in time: %s', $driver, $lastError));
    }

    private function installFixtureSchema(ConnectionInterface $connection, string $driver): void
    {
        foreach ($this->schemaSql($driver) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function schemaSql(string $driver): array
    {
        return match ($driver) {
            'pgsql' => [
                'DROP TABLE IF EXISTS vs_users',
                'DROP TABLE IF EXISTS vs_accounts',
                'CREATE TABLE vs_accounts (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name VARCHAR(120) NOT NULL)',
                "CREATE TABLE vs_users (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, account_id BIGINT NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_vs_users_account FOREIGN KEY (account_id) REFERENCES vs_accounts(id) ON DELETE CASCADE)",
                'CREATE UNIQUE INDEX uq_vs_users_email ON vs_users (email)',
            ],
            'mysql', 'mariadb' => [
                'DROP TABLE IF EXISTS vs_users',
                'DROP TABLE IF EXISTS vs_accounts',
                'CREATE TABLE vs_accounts (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL) ENGINE=InnoDB',
                "CREATE TABLE vs_users (id BIGINT AUTO_INCREMENT PRIMARY KEY, account_id BIGINT NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_vs_users_account FOREIGN KEY (account_id) REFERENCES vs_accounts(id) ON DELETE CASCADE) ENGINE=InnoDB",
                'CREATE UNIQUE INDEX uq_vs_users_email ON vs_users (email)',
            ],
            default => throw new \InvalidArgumentException(sprintf('Unsupported driver [%s].', $driver)),
        };
    }

    private function makeIntrospector(string $driver, ConnectionInterface $connection): SchemaIntrospectorInterface
    {
        return match ($driver) {
            'pgsql' => new PgsqlSchemaIntrospector($connection),
            'mysql' => new MysqlSchemaIntrospector($connection),
            'mariadb' => new MariadbSchemaIntrospector($connection),
            default => throw new \InvalidArgumentException(sprintf('Unsupported driver [%s].', $driver)),
        };
    }

    /**
     * @param list<\Quantum\Database\Schema\SchemaIndex> $indexes
     */
    private function findIndex(array $indexes, string $column): ?\Quantum\Database\Schema\SchemaIndex
    {
        foreach ($indexes as $index) {
            if (in_array($column, $index->columns, true)) {
                return $index;
            }
        }

        return null;
    }

    private function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if (preg_match("/^'(.*)'::[a-z0-9_ ]+$/i", $string, $matches) === 1) {
            return $matches[1];
        }
        if (str_starts_with($string, "'") && str_ends_with($string, "'")) {
            return substr($string, 1, -1);
        }

        return $string;
    }
}

final class ExternalDatabaseContainer
{
    private function __construct(
        public readonly string $name,
        public readonly int $hostPort,
    ) {
    }

    public static function boot(string $driver, string $image, int $containerPort): self
    {
        $name = sprintf('voltstack-schema-%s-%s', $driver, bin2hex(random_bytes(4)));
        $env = match ($driver) {
            'pgsql' => [
                '-e POSTGRES_DB=voltstack',
                '-e POSTGRES_USER=voltstack',
                '-e POSTGRES_PASSWORD=voltstack',
            ],
            'mysql' => [
                '-e MYSQL_DATABASE=voltstack',
                '-e MYSQL_ROOT_PASSWORD=voltstack',
            ],
            'mariadb' => [
                '-e MARIADB_DATABASE=voltstack',
                '-e MARIADB_ROOT_PASSWORD=voltstack',
            ],
            default => throw new \InvalidArgumentException(sprintf('Unsupported driver [%s].', $driver)),
        };

        $run = ExternalCommand::run(sprintf(
            'docker run -d --rm --name %s -p 127.0.0.1::%d %s %s',
            $name,
            $containerPort,
            implode(' ', $env),
            $image,
        ));

        if ($run->exitCode !== 0) {
            throw new SkippedTestError(sprintf(
                'Unable to start %s integration container: %s',
                $driver,
                trim($run->stderr . PHP_EOL . $run->stdout),
            ));
        }

        $port = ExternalCommand::run(sprintf(
            'docker port %s %d/tcp',
            $name,
            $containerPort,
        ));

        if ($port->exitCode !== 0 || trim($port->stdout) === '') {
            ExternalCommand::run(sprintf('docker rm -f %s', $name));
            TestCase::fail(sprintf('Could not resolve published port for container [%s].', $name));
        }

        $endpoint = trim(str_replace("\r", '', $port->stdout));
        $parts = preg_split('/[:]/', $endpoint);
        $hostPort = (int) end($parts);

        return new self($name, $hostPort);
    }

    public function shutdown(): void
    {
        ExternalCommand::run(sprintf('docker rm -f %s', $this->name));
    }
}

final class ExternalCommand
{
    public static function run(string $command): ExternalCommandResult
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, getcwd());
        if (!is_resource($process)) {
            return new ExternalCommandResult(1, '', 'Unable to launch process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new ExternalCommandResult(
            $exitCode,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        );
    }
}

final readonly class ExternalCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }
}