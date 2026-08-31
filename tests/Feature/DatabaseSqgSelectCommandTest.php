<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseSqgSelectCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-sqg-select-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_sqg_select_pretend_exposes_optimizer_summary_and_join_reorder_trace(): void
    {
        $spec = json_encode([
            'from' => ['table' => 'users', 'alias' => 'u'],
            'select' => ['u.id', 'p.user_id', 'o.user_id', 'a.profile_id'],
            'joins' => [
                ['type' => 'INNER', 'from_alias' => 'u', 'table' => 'profiles', 'alias' => 'p', 'on' => 'u.id = p.user_id'],
                ['type' => 'INNER', 'from_alias' => 'u', 'table' => 'orders', 'alias' => 'o', 'on' => 'u.id = o.user_id'],
                ['type' => 'INNER', 'from_alias' => 'p', 'table' => 'addresses', 'alias' => 'a', 'on' => 'p.id = a.profile_id'],
            ],
            'where' => [
                'u.id = :user_id',
                'o.user_id >= :min_order_user_id',
                'a.profile_id = :profile_id',
                'a.country_id = :country_id',
            ],
            'params' => [
                'user_id' => 1,
                'min_order_user_id' => 2,
                'profile_id' => 3,
                'country_id' => 7,
            ],
            'order_by' => [
                ['expr' => 'u.id', 'direction' => 'ASC'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->runConsole(['volt', 'db:sqg-select', '--pretend', '--spec=' . $spec]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('SQG SQL: SELECT', $result['stdout']);
        self::assertStringContainsString('Optimizer: strategy=safe_rule_bundle_v1', $result['stdout']);
        self::assertStringContainsString('Rules:', $result['stdout']);
        self::assertStringContainsString('Candidates:', $result['stdout']);
        self::assertStringContainsString('candidate:no_op cost=', $result['stdout']);
        self::assertStringContainsString('selected=no', $result['stdout']);
        self::assertStringContainsString('candidate:predicate_normalization_v1+boolean_predicate_normalization_v1+predicate_pushdown_v1+join_reorder_v1 cost=', $result['stdout']);
        self::assertStringContainsString('selected=yes', $result['stdout']);
        self::assertStringContainsString('join_reorder_v1', $result['stdout']);
        self::assertStringContainsString('Join reorder: selected=u>p>a>o', $result['stdout']);
        self::assertStringContainsString('  - u>p>a>o base=u', $result['stdout']);
        self::assertStringContainsString('Planner: logical_root=', $result['stdout']);
        self::assertStringContainsString('Dry-run activado: no se ejecutaron cambios.', $result['stdout']);
    }

    public function test_cli_sqg_select_accepts_relaxed_powershell_inline_spec_syntax(): void
    {
        $result = $this->runConsole([
            'volt',
            'db:sqg-select',
            '--pretend',
            '--spec={from:{table:users,alias:u},select:[u.id]}',
        ]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('SQG SQL: SELECT "u"."id" FROM "users" AS "u"', $result['stdout']);
        self::assertStringContainsString('Optimizer: strategy=no_op', $result['stdout']);
        self::assertStringContainsString('Dry-run activado: no se ejecutaron cambios.', $result['stdout']);
    }

    public function test_cli_sqg_select_executes_and_returns_rows_with_pipeline_summary(): void
    {
        $this->runConsole(['volt', 'db:query', 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, status INTEGER NOT NULL)']);
        $this->runConsole(['volt', 'db:query', 'DELETE FROM users']);
        $this->runConsole(['volt', 'db:query', 'INSERT INTO users (id, status) VALUES (1, 2)']);

        $spec = json_encode([
            'from' => ['table' => 'users', 'alias' => 'u'],
            'select' => ['u.id', 'u.status'],
            'where' => ['u.id = :user_id'],
            'params' => ['user_id' => 1],
            'order_by' => [
                ['expr' => 'u.id', 'direction' => 'ASC'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->runConsole(['volt', 'db:sqg-select', '--spec=' . $spec]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('SQG SQL: SELECT', $result['stdout']);
        self::assertStringContainsString('Optimizer: strategy=', $result['stdout']);
        self::assertStringContainsString('Planner: logical_root=', $result['stdout']);
        self::assertStringContainsString('id', $result['stdout']);
        self::assertStringContainsString('status', $result['stdout']);
        self::assertStringContainsString('1', $result['stdout']);
        self::assertStringContainsString('2', $result['stdout']);
        self::assertStringContainsString('Rows: 1', $result['stdout']);
    }

    public function test_cli_sqg_select_preserves_parameter_binding_after_boolean_predicate_normalization(): void
    {
        $this->runConsole(['volt', 'db:query', 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)']);
        $this->runConsole(['volt', 'db:query', 'DELETE FROM users']);
        $this->runConsole(['volt', 'db:query', "INSERT INTO users (id, name) VALUES (1, 'demo sqg')"]);
        $this->runConsole(['volt', 'db:query', "INSERT INTO users (id, name) VALUES (2, 'demo and')"]);
        $this->runConsole(['volt', 'db:query', "INSERT INTO users (id, name) VALUES (3, 'demo page')"]);

        $spec = json_encode([
            'from' => ['table' => 'users', 'alias' => 'u'],
            'select' => ['u.id', 'u.name'],
            'where' => ['u.id >= :min_id', 'u.name <> :excluded_name'],
            'params' => [
                'min_id' => 1,
                'excluded_name' => 'demo sqg',
            ],
            'order_by' => [
                ['expr' => 'u.id', 'direction' => 'ASC'],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->runConsole(['volt', 'db:sqg-select', '--spec=' . $spec]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Optimizer: strategy=boolean_predicate_normalization_v1', $result['stdout']);
        self::assertStringContainsString('demo and', $result['stdout']);
        self::assertStringContainsString('demo page', $result['stdout']);
        self::assertStringContainsString('Rows: 2', $result['stdout']);
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
                'name' => 'VoltStack SQG Select Feature Test',
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
                    'remote_replay_validation_mode' => 'allow',
                    'remote_replay_validation_receipt_max_age_seconds' => 0,
                    'remote_replay_validation_receipt_reuse_scope' => 'current_node',
                    'remote_replay_validation_receipt_trusted_nodes' => [],
                    'remote_replay_validation_receipt_propagation_max_age_seconds' => 0,
                    'remote_replay_validation_receipt_propagation_health_limit' => 250,
                    'remote_replay_validation_receipt_propagation_trusted_nodes' => [],
                    'remote_replay_validation_receipt_cleanup_propagation_max_age_seconds' => 0,
                    'remote_replay_validation_receipt_cleanup_propagation_health_limit' => 250,
                    'remote_replay_validation_receipt_cleanup_propagation_trusted_nodes' => [],
                    'remote_replay_validation_receipt_replicated_max_age_seconds' => 0,
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
