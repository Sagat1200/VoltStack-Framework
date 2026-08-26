<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseQueryCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-query-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_query_supports_pretend_plan_output(): void
    {
        $result = $this->runConsole(['volt', 'db:query', 'SELECT 1 AS one', '--pretend']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Plan: kind=raw_query', $result['stdout']);
        self::assertStringContainsString('Budget: connection=primary', $result['stdout']);
        self::assertStringContainsString('Dry-run activado: no se ejecutaron cambios.', $result['stdout']);
    }

    public function test_cli_query_enforces_max_rows_budget(): void
    {
        $result = $this->runConsole(['volt', 'db:query', 'SELECT 1 AS one UNION ALL SELECT 2 AS one', '--max-rows=1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('db:query failed: failure=resource_exhausted', $result['stderr']);
        self::assertStringContainsString('event: failed', $result['stderr']);
    }

    public function test_cli_query_reports_diagnostics_for_successful_statement_and_select(): void
    {
        $statement = $this->runConsole(['volt', 'db:query', 'CREATE TABLE IF NOT EXISTS f36_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL)']);
        $query = $this->runConsole(['volt', 'db:query', "SELECT 'ok' AS status"]);

        self::assertSame(0, $statement['exit']);
        self::assertStringContainsString('Plan: kind=raw_execute', $statement['stdout']);
        self::assertStringContainsString('Statement OK. Affected rows:', $statement['stdout']);
        self::assertStringContainsString('Diagnostic: outcome=completed', $statement['stdout']);

        self::assertSame(0, $query['exit']);
        self::assertStringContainsString('Plan: kind=raw_query', $query['stdout']);
        self::assertStringContainsString('status', $query['stdout']);
        self::assertStringContainsString('ok', $query['stdout']);
        self::assertStringContainsString('Rows: 1', $query['stdout']);
        self::assertStringContainsString('Diagnostic: outcome=completed', $query['stdout']);
    }

    public function test_cli_query_pretend_marks_idempotent_mutation_as_retryable_when_policy_allows_it(): void
    {
        $result = $this->runConsole([
            'volt',
            'db:query',
            'UPDATE f36_logs SET message = 1 WHERE id = 1',
            '--pretend',
            '--idempotency-key=mutation-f36-1',
        ]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Plan: kind=raw_execute', $result['stdout']);
        self::assertStringContainsString('retryable=yes', $result['stdout']);
        self::assertStringContainsString('idempotency=present', $result['stdout']);
        self::assertStringContainsString('legacy_replay_mode=allow', $result['stdout']);
        self::assertStringContainsString('remote_replay_attestation_mode=allow', $result['stdout']);
        self::assertStringContainsString('remote_replay_attestation_max_age_seconds=0', $result['stdout']);
    }

    /**
     * @param array<int, string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runConsole(array $argv): array
    {
        $output = new Output();
        $console = new ConsoleApplication($this->basePath, output: $output);

        return [
            'exit' => $console->run($argv),
            'stdout' => $output->stdout(),
            'stderr' => $output->stderr(),
        ];
    }

    private function loadApp(): Application
    {
        /** @var Application $app */
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        return $app;
    }

    private function makeTempProject(string $basePath): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($storagePath, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Query Feature Test',
                'env' => 'testing',
                'debug' => true,
                'providers' => [
                    DatabaseServiceProvider::class,
                ],
            ], true) . ";\n"
        );

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'default' => 'primary',
                'connections' => [
                    'primary' => [
                        'driver' => 'sqlite',
                        'path' => $sqlitePath,
                        'memory' => false,
                        'foreign_keys' => true,
                    ],
                ],
                'timeouts' => [
                    'soft_timeout_ms' => 30000,
                    'max_idle_ms_before_ping' => 0,
                ],
                'query_limits' => [
                    'max_rows' => 100000,
                    'max_depth' => 32,
                ],
                'observability' => [
                    'audit' => true,
                    'slow_query_ms' => 1,
                ],
                'resilience' => [
                    'retry_limit' => 1,
                    'retry_backoff_ms' => 0,
                    'retry_mutations_when_idempotent' => true,
                    'circuit_breaker' => [
                        'failure_threshold' => 2,
                        'cooldown_ms' => 30000,
                    ],
                ],
                'idempotency' => [
                    'pending_ttl_seconds' => 300,
                    'legacy_replay_mode' => 'allow',
                    'remote_replay_attestation_mode' => 'allow',
                    'remote_replay_attestation_max_age_seconds' => 0,
                ],
                'security' => [
                    'redact_sensitive' => true,
                    'policies' => [
                        'soft_delete_filter' => true,
                    ],
                ],
                'cli' => [
                    'allow_raw_query' => true,
                ],
            ], true) . ";\n"
        );

        $bootstrapPhp = <<<PHP
<?php

declare(strict_types=1);

require_once %s;

use Quantum\\Bootstrap\\Bootstrapper;
use VoltStack\\Framework\\Application;

\$app = new Application(%s);
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

foreach ((array) \$app->config('app.providers', []) as \$provider) {
    \$app->register(\$provider);
}

\$app->boot();

return \$app;
PHP;

        file_put_contents(
            $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php',
            sprintf($bootstrapPhp, var_export($autoloadPath, true), var_export($basePath, true))
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
