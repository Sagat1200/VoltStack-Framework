<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\Engine\HttpDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\JsonLineDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\NullDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\OpenTelemetryDatabaseTelemetryDispatcher;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseTelemetryDispatcherBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-observability-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_in_memory_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'in_memory');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_jsonl_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'jsonl');
        $app->make(ConfigRepository::class)->set('database.observability.jsonl_path', $this->basePath . DIRECTORY_SEPARATOR . 'database-events.jsonl');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(JsonLineDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_null_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'null');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(NullDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_webhook_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'webhook');
        $app->make(ConfigRepository::class)->set('database.observability.webhook_url', 'https://monitoring.internal/voltstack/database');
        $app->make(ConfigRepository::class)->set('database.observability.webhook_timeout_ms', 5000);
        $app->make(ConfigRepository::class)->set('database.observability.webhook_headers', [
            'Authorization' => 'Bearer token',
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(HttpDatabaseTelemetryDispatcher::class, $dispatcher);
        self::assertSame('https://monitoring.internal/voltstack/database', $dispatcher->endpoint());
    }

    public function test_it_resolves_opentelemetry_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'opentelemetry');
        $app->make(ConfigRepository::class)->set('database.observability.opentelemetry.endpoint', 'https://collector.internal/v1/logs');
        $app->make(ConfigRepository::class)->set('database.observability.opentelemetry.service_name', 'voltstack-db');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(OpenTelemetryDatabaseTelemetryDispatcher::class, $dispatcher);
        self::assertSame('https://collector.internal/v1/logs', $dispatcher->endpoint());
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

            unlink($target);
        }

        rmdir($path);
    }
}
