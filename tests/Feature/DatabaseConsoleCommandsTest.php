<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;

final class DatabaseConsoleCommandsTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private string $migrationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-console-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
        $this->migrationsPath = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        mkdir($this->basePath, 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database', 0777, true);
        mkdir($this->migrationsPath, 0777, true);

        $this->writeBootstrap();
        $this->writeConfig();
        $this->writeMigration();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_console_application_registers_and_executes_database_commands(): void
    {
        $console = new ConsoleApplication($this->basePath);

        self::assertSame(0, $console->run(['volt', 'database:migrate']));
        self::assertSame(1, $this->countRows('quantum_migrations'));
        self::assertTrue($this->hasTable('console_items'));

        self::assertSame(0, $console->run(['volt', 'database:status']));
        self::assertSame(0, $console->run(['volt', 'database:rollback']));
        self::assertFalse($this->hasTable('console_items'));
    }

    private function writeBootstrap(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php', <<<'PHP'
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

$app = new Application(dirname(__DIR__));
$bootstrapper = new Bootstrapper($app);
$bootstrapper->loadConfiguration();

foreach ((array) $app->config('app.providers', []) as $provider) {
    $app->register($provider);
}

$app->boot();

return $app;
PHP
);
    }

    private function writeConfig(): void
    {
        file_put_contents($this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'providers' => [],
];
PHP
);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => $this->databasePath,
                    ],
                ],
                'telemetry' => [
                    'enabled' => true,
                ],
            ], true) . ";\n",
        );
    }

    private function writeMigration(): void
    {
        file_put_contents($this->migrationsPath . DIRECTORY_SEPARATOR . '2026_09_24_000001_create_console_items.php', <<<'PHP'
<?php

return new class implements \Quantum\Database\Migration\MigrationInterface {
    public function up(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->create('console_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);
    }

    public function down(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->dropIfExists('console_items');
    }
};
PHP
);
    }

    private function countRows(string $table): int
    {
        $pdo = new PDO('sqlite:' . $this->databasePath);

        return (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchColumn();
    }

    private function hasTable(string $table): bool
    {
        $pdo = new PDO('sqlite:' . $this->databasePath);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name = ?');
        $statement->execute(['table', $table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
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

            unlink($target);
        }

        rmdir($path);
    }
}
