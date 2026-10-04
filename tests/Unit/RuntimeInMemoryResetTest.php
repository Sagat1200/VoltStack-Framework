<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;
use Quantum\Controllers\Observability\Engine\InMemoryControllerEventDispatcher;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Reset\ResetManager;

final class RuntimeInMemoryResetTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-inmemory-reset-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_reset_manager_clears_in_memory_runtime_buffers_after_a_request_cycle(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('telemetry.exporter', 'in_memory');
        $app->make(ConfigRepository::class)->set('controller_observability.dispatcher', 'in_memory');

        $exporter = $app->make(TelemetryExporterInterface::class);
        $dispatcher = $app->make(ControllerEventDispatcherInterface::class);

        self::assertInstanceOf(InMemoryTelemetryExporter::class, $exporter);
        self::assertInstanceOf(InMemoryControllerEventDispatcher::class, $dispatcher);

        $exporter->export(new TelemetrySignal(
            name: 'request.completed',
            type: 'event',
            source: 'runtime',
            occurredAt: date(DATE_ATOM),
            payload: ['ok' => true],
        ));
        $dispatcher->dispatch(new RuntimeResetTestControllerEvent());

        self::assertCount(1, $exporter->signals());
        self::assertCount(1, $dispatcher->events());

        $report = $app->make(ResetManager::class)->reset($app);

        self::assertTrue($report->successful());
        self::assertCount(0, $exporter->signals());
        self::assertCount(0, $dispatcher->events());
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

final class RuntimeResetTestControllerEvent implements ControllerEventInterface
{
    public function name(): string
    {
        return 'controller.executed';
    }

    public function version(): int
    {
        return 1;
    }

    public function executionId(): string
    {
        return 'exec-1';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public function sequence(): int
    {
        return 1;
    }

    public function payload(): array
    {
        return ['status' => 'ok'];
    }
}
