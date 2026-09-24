<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Facades\DB;
use Quantum\Facades\Schema;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabasePublicApiFacadeTest extends TestCase
{
    private string $basePath;
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-public-api-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_public_database_service_and_facades_share_the_same_scoped_surface(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/public-api', 'GET'));

        try {
            Schema::create('public_items', function (TableBlueprint $table): void {
                $table->id();
                $table->string('name');
            }, true);

            DB::table('public_items')->insert(['name' => 'VoltStack']);
            DB::transaction(function () {
                DB::table('public_items')->insert(['name' => 'Quantum']);
            });

            $database = $app->make(DatabaseInterface::class);
            $status = $database->status();
            $rows = $database->table('public_items')
                ->select('name')
                ->orderBy('name')
                ->get()
                ->rows();
        } finally {
            $scope->end();
        }

        self::assertSame('default', $status->defaultConnectionName);
        self::assertSame('default', $status->connectionName);
        self::assertSame('pdo.sqlite', $status->driver);
        self::assertSame('sqlite', $status->platform);
        self::assertTrue($status->telemetryEnabled);
        self::assertSame(['Quantum', 'VoltStack'], array_values(array_map(
            static fn(array $row): string => (string) $row['name'],
            $rows,
        )));
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
