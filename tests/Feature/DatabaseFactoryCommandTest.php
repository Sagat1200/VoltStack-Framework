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

final class DatabaseFactoryCommandTest extends TestCase
{
    private string $basePath;

    protected function tearDown(): void
    {
        if (isset($this->basePath) && is_dir($this->basePath)) {
            $this->deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    public function test_cli_factory_status_and_sample_are_deterministic_for_same_seed(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-factories-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);

        $status = $this->runConsole(['volt', 'db:factory-status']);
        self::assertSame(0, $status['exit']);
        self::assertStringContainsString('factory-user', $status['stdout']);
        self::assertStringContainsString(F18FactoryUser::class, $status['stdout']);

        $sampleA = $this->runConsole(['volt', 'db:factory-sample', 'factory-user', '--count=2', '--seed=321', '--state=admin', '--json']);
        $sampleB = $this->runConsole(['volt', 'db:factory-sample', 'factory-user', '--count=2', '--seed=321', '--state=admin', '--json']);
        $sampleC = $this->runConsole(['volt', 'db:factory-sample', 'factory-user', '--count=2', '--seed=999', '--state=admin', '--json']);

        self::assertSame(0, $sampleA['exit']);
        self::assertSame($sampleA['stdout'], $sampleB['stdout']);
        self::assertNotSame($sampleA['stdout'], $sampleC['stdout']);
        self::assertStringContainsString('"role": "admin"', $sampleA['stdout']);
        self::assertStringContainsString('"seqTag": "alpha"', $sampleA['stdout']);
        self::assertStringContainsString('"seqTag": "beta"', $sampleA['stdout']);
    }

    public function test_factory_seeders_can_create_entities_through_orm(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-factories-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);

        $app = $this->loadApp();
        $this->createTables($app);

        $seed = $this->runConsole(['volt', 'db:seed', 'factory-users']);
        self::assertSame(0, $seed['exit']);
        self::assertStringContainsString('Seeders ejecutados: 1', $seed['stdout']);

        self::assertSame(2, $this->countRows($app, 'f18_users'));
        self::assertSame('admin', $this->firstValue($app, 'SELECT role FROM f18_users ORDER BY id ASC LIMIT 1'));
        self::assertSame('alpha', $this->firstValue($app, 'SELECT seq_tag FROM f18_users ORDER BY id ASC LIMIT 1'));
        self::assertSame('beta', $this->firstValue($app, 'SELECT seq_tag FROM f18_users ORDER BY id ASC LIMIT 1 OFFSET 1'));
    }

    public function test_factory_sample_supports_custom_constructor_instantiation(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-factories-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);

        $sample = $this->runConsole(['volt', 'db:factory-sample', F18FactoryUser::class, '--seed=111', '--json']);

        self::assertSame(0, $sample['exit']);
        self::assertStringContainsString('"name":', $sample['stdout']);
        self::assertStringContainsString('"email":', $sample['stdout']);
        self::assertStringContainsString('"status": "draft"', $sample['stdout']);
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

    private function createTables(Application $app): void
    {
        $connection = $app->make(ConnectionInterface::class);
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS f18_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL, seq_tag TEXT NOT NULL)');
    }

    private function countRows(Application $app, string $table): int
    {
        $row = $app->make(ConnectionInterface::class)
            ->executeQuery(sprintf('SELECT COUNT(*) AS c FROM %s', $table))
            ->fetchOneAssoc();

        return (int) ($row['c'] ?? 0);
    }

    private function firstValue(Application $app, string $sql): ?string
    {
        $row = $app->make(ConnectionInterface::class)->executeQuery($sql)->fetchOneAssoc();
        if (!is_array($row)) {
            return null;
        }

        return (string) array_values($row)[0];
    }

    private function makeTempProject(string $basePath): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $factoryPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'factories';
        $seederPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $cacheDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'orm' . DIRECTORY_SEPARATOR . 'metadata';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $namespace = 'TempFactories\\T' . substr(md5($basePath), 0, 8);

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($factoryPath, 0777, true);
        mkdir($seederPath, 0777, true);
        mkdir($storagePath, 0777, true);
        mkdir($cacheDir, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Factory Feature Test',
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
                        F18FactoryUser::class,
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
                'seeders' => [
                    'paths' => [
                        'database/seeders',
                    ],
                    'classes' => [],
                    'require_force_in_production' => true,
                ],
                'factories' => [
                    'paths' => [
                        'database/factories',
                    ],
                    'classes' => [],
                    'default_seed' => 12345,
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

        file_put_contents(
            $factoryPath . DIRECTORY_SEPARATOR . '001_user_factory.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Factory\AbstractFactory;
use Quantum\Database\Factory\FactoryContext;
use VoltStack\Test\Feature\F18FactoryUser;

final class UserFactory extends AbstractFactory
{
    public function name(): string
    {
        return 'factory-user';
    }

    public function description(): string
    {
        return 'Factory reproducible para usuarios de prueba.';
    }

    public function entityClass(): string
    {
        return F18FactoryUser::class;
    }

    public function definition(FactoryContext $context): array
    {
        return [
            'name' => 'User ' . $context->random()->slug('name', 4),
            'email' => $context->random()->email('user'),
            'role' => 'member',
            'status' => 'draft',
            'seqTag' => $context->sequence(['alpha', 'beta', 'gamma']),
        ];
    }

    public function states(): array
    {
        return [
            'admin' => static fn(array $attributes, FactoryContext $context): array => array_merge($attributes, ['role' => 'admin']),
            'active' => static fn(array $attributes, FactoryContext $context): array => array_merge($attributes, ['status' => 'active']),
        ];
    }

    public function instantiate(array $attributes, FactoryContext $context): object
    {
        $entity = new F18FactoryUser($attributes['name'], $attributes['email']);
        $entity->role = (string) $attributes['role'];
        $entity->status = (string) $attributes['status'];
        $entity->seqTag = (string) $attributes['seqTag'];

        return $entity;
    }
}
PHP
            , $namespace)
        );

        file_put_contents(
            $seederPath . DIRECTORY_SEPARATOR . '001_factory_users.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Seeder\AbstractSeeder;
use Quantum\Database\Seeder\SeedExecutionContext;

final class FactoryUsersSeeder extends AbstractSeeder
{
    public function name(): string
    {
        return 'factory-users';
    }

    public function description(): string
    {
        return 'Puebla usuarios usando la factory reproducible.';
    }

    public function run(SeedExecutionContext $context): void
    {
        $users = $context->factories()
            ->factory('factory-user')
            ->seed(777)
            ->count(2)
            ->state('admin')
            ->create();

        $context->references()->set('factory.first_user_id', $users[0]->id);
    }
}
PHP
            , $namespace)
        );
    }

    private function deleteDirectory(string $path): void
    {
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

#[ORM\Entity(table: 'f18_users')]
final class F18FactoryUser
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'int')]
    #[ORM\GeneratedValue('IDENTITY')]
    public ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string')]
    public string $name;

    #[ORM\Column(name: 'email', type: 'string')]
    public string $email;

    #[ORM\Column(name: 'role', type: 'string')]
    public string $role = 'member';

    #[ORM\Column(name: 'status', type: 'string')]
    public string $status = 'draft';

    #[ORM\Column(name: 'seq_tag', type: 'string')]
    public string $seqTag = 'alpha';

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
    }
}
