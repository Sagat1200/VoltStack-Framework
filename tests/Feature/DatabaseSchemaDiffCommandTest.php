<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;
use VoltStack\Framework\Provider\OrmServiceProvider;

final class DatabaseSchemaDiffCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-schema-diff-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_schema_diff_reports_missing_tables_and_columns(): void
    {
        $app = $this->loadApp();
        $this->createLiveSchema($app);

        $diff = $this->runConsole(['volt', 'db:schema-diff']);
        $sql = $this->runConsole(['volt', 'db:schema-diff', '--sql']);

        self::assertSame(0, $diff['exit']);
        self::assertStringContainsString('ADD_COLUMN', $diff['stdout']);
        self::assertStringContainsString('CREATE_TABLE', $diff['stdout']);
        self::assertStringContainsString('f20_users.status', $diff['stdout']);
        self::assertStringContainsString('f20_logs', $diff['stdout']);

        self::assertSame(0, $sql['exit']);
        self::assertStringContainsString('ALTER TABLE "f20_users" ADD COLUMN "status" TEXT NOT NULL DEFAULT \'draft\';', $sql['stdout']);
        self::assertStringContainsString('CREATE TABLE "f20_logs"', $sql['stdout']);
    }

    public function test_schema_diff_supports_json_output(): void
    {
        $app = $this->loadApp();
        $this->createLiveSchema($app);

        $json = $this->runConsole(['volt', 'db:schema-diff', '--json']);

        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"kind": "add_column"', $json['stdout']);
        self::assertStringContainsString('"kind": "create_table"', $json['stdout']);
        self::assertStringContainsString('"table": "f20_users"', $json['stdout']);
    }

    public function test_make_migration_can_embed_current_schema_diff_plan(): void
    {
        $app = $this->loadApp();
        $this->createLiveSchema($app);

        $make = $this->runConsole(['volt', 'db:make-migration', 'sync_f20_schema', '--diff']);

        self::assertSame(0, $make['exit']);
        self::assertStringContainsString('Migración creada:', $make['stdout']);

        $files = glob($this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $contents = file_get_contents($files[0]);
        self::assertIsString($contents);
        self::assertStringContainsString('Suggested plan from current schema diff', $contents);
        self::assertStringContainsString('ALTER TABLE "f20_users" ADD COLUMN "status" TEXT NOT NULL DEFAULT \'draft\';', $contents);
        self::assertStringContainsString('CREATE TABLE "f20_logs"', $contents);
    }

    public function test_schema_diff_projects_many_to_many_pivot_table_indexes_and_foreign_keys(): void
    {
        $json = $this->runConsole(['volt', 'db:schema-diff', '--json']);
        $sql = $this->runConsole(['volt', 'db:schema-diff', '--sql']);

        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"table": "f20_post_tags"', $json['stdout']);
        self::assertStringContainsString('"kind": "create_index"', $json['stdout']);
        self::assertStringContainsString('"kind": "add_foreign_key"', $json['stdout']);

        self::assertSame(0, $sql['exit']);
        self::assertStringContainsString('CREATE TABLE "f20_post_tags" ("post_id" INTEGER NOT NULL, "tag_id" INTEGER NOT NULL);', $sql['stdout']);
        self::assertStringContainsString('CREATE INDEX "idx_f20_post_tags_post_id" ON "f20_post_tags" ("post_id");', $sql['stdout']);
        self::assertStringContainsString('CREATE INDEX "idx_f20_post_tags_tag_id" ON "f20_post_tags" ("tag_id");', $sql['stdout']);
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

    private function createLiveSchema(Application $app): void
    {
        $db = $app->make(ConnectionInterface::class);
        $db->executeStatement('CREATE TABLE IF NOT EXISTS f20_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
    }

    private function makeTempProject(string $basePath): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $migrationPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $cacheDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'metadata';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($migrationPath, 0777, true);
        mkdir($storagePath, 0777, true);
        mkdir($cacheDir, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Schema Diff Feature Test',
                'env' => 'testing',
                'debug' => true,
                'providers' => [
                    DatabaseServiceProvider::class,
                    OrmServiceProvider::class,
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
                'metadata' => [
                    'entity_paths' => [],
                    'entities' => [
                        F20DiffUser::class,
                        F20DiffLog::class,
                        F20DiffPost::class,
                        F20DiffTag::class,
                    ],
                    'cache_dir' => $cacheDir,
                    'custom_types' => [],
                ],
                'timeouts' => [
                    'soft_timeout_ms' => 30000,
                    'max_idle_ms_before_ping' => 0,
                ],
                'query_limits' => [
                    'max_rows' => 100000,
                    'max_depth' => 32,
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
                'migrations' => [
                    'paths' => [
                        'database/migrations',
                    ],
                    'classes' => [],
                    'table' => 'framework_migrations',
                ],
                'schema' => [
                    'enabled' => true,
                    'strict' => true,
                    'cache' => [
                        'enabled' => false,
                        'version' => 1,
                    ],
                ],
                'orm' => [
                    'auto_flush_on_terminate' => false,
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

#[ORM\Entity(table: 'f20_users')]
final class F20DiffUser
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'email', type: 'string')]
    public string $email = '';

    #[ORM\Column(name: 'status', type: 'string', default: 'draft')]
    public string $status = 'draft';
}

#[ORM\Entity(table: 'f20_logs')]
final class F20DiffLog
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'message', type: 'string')]
    public string $message = '';
}

#[ORM\Entity(table: 'f20_posts')]
final class F20DiffPost
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'title', type: 'string')]
    public string $title = '';

    /** @var array<int,F20DiffTag> */
    #[ORM\ManyToMany(
        targetEntity: F20DiffTag::class,
        inversedBy: 'posts',
        joinTable: 'f20_post_tags',
        joinColumn: 'post_id',
        inverseJoinColumn: 'tag_id',
    )]
    public array $tags = [];
}

#[ORM\Entity(table: 'f20_tags')]
final class F20DiffTag
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    public string $name = '';

    /** @var array<int,F20DiffPost> */
    #[ORM\ManyToMany(targetEntity: F20DiffPost::class, mappedBy: 'tags')]
    public array $posts = [];
}
