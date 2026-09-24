<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Http\Request;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseTelemetryFeatureTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private string $migrationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-telemetry-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
        $this->migrationsPath = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        mkdir($this->basePath, 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database', 0777, true);
        mkdir($this->migrationsPath, 0777, true);

        file_put_contents($this->migrationsPath . DIRECTORY_SEPARATOR . '2026_09_24_000001_create_telemetry_logs.php', <<<'PHP'
<?php

return new class implements \Quantum\Database\Migration\MigrationInterface {
    public function up(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->create('telemetry_logs', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);
    }

    public function down(\Quantum\Database\Schema\SchemaManager $schema): void
    {
        $schema->dropIfExists('telemetry_logs');
    }
};
PHP
);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_database_operations_emit_minimum_telemetry_signals(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);
        $config->set('database.telemetry.enabled', true);
        $config->set('telemetry.exporter', 'in_memory');

        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/telemetry', 'POST'));

        try {
            $database = $app->make(DatabaseInterface::class);

            $database->schema()->create('telemetry_items', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);

            $database->transaction(function () use ($database): void {
                $database->table('telemetry_items')->insert(['name' => 'signal']);
            });

            $database->migrate($this->migrationsPath);
        } finally {
            $scope->end();
        }

        $exporter = $app->make(TelemetryExporterInterface::class);
        self::assertInstanceOf(InMemoryTelemetryExporter::class, $exporter);

        $names = array_values(array_map(
            static fn(\Quantum\Telemetry\TelemetrySignal $signal): string => $signal->name,
            $exporter->signals(),
        ));

        self::assertContains('database.query.executed', $names);
        self::assertContains('database.transaction.began', $names);
        self::assertContains('database.transaction.committed', $names);
        self::assertContains('database.migration.migrated', $names);
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
