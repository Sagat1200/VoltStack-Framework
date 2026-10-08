<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Scope\ConfigurationOverrideWriter;
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

    public function test_reset_manager_clears_scoped_configuration_overrides_after_a_request_cycle(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_security.authorization.max_policy_evaluations', 12);
        $app->enterRequestScope();

        try {
            $writer = $app->make(ConfigurationOverrideWriter::class);
            $writer->set('controller_security.authorization.max_policy_evaluations', 48);

            self::assertSame(48, $app->configBridge()->scoped('controller_security.authorization.max_policy_evaluations'));

            $report = $app->make(ResetManager::class)->reset($app);

            self::assertTrue($report->successful());
            self::assertSame(12, $app->configBridge()->scoped('controller_security.authorization.max_policy_evaluations'));
        } finally {
            if ($app->hasActiveScope()) {
                $app->leaveScope();
            }
        }
    }

    public function test_reset_manager_continues_running_resetters_after_a_failure(): void
    {
        $app = new Application($this->basePath);
        $manager = $app->make(ResetManager::class);

        $manager->register(static function (Application $app): void {
            throw new \RuntimeException('first resetter failed');
        });
        $manager->register(static function (Application $app): void {
            $app->instance('runtime.reset.after_failure', true);
        });

        $report = $manager->reset($app);

        self::assertFalse($report->successful());
        self::assertGreaterThanOrEqual(1, $report->executedCount());
        self::assertCount(1, $report->errors());
        self::assertTrue($app->make('runtime.reset.after_failure'));
    }

    public function test_reset_manager_reports_flush_scope_failures_without_hiding_other_errors(): void
    {
        $app = new class($this->basePath) extends Application
        {
            public function flushScope(): void
            {
                throw new \RuntimeException('flush scope failed');
            }
        };

        $manager = $app->make(ResetManager::class);
        $manager->register(static function (Application $app): void {
            throw new \RuntimeException('resetter failed');
        });
        $manager->register(static function (Application $app): void {
            $app->instance('runtime.reset.flush_failure.after_resetter', true);
        });

        $report = $manager->reset($app);

        self::assertFalse($report->successful());
        self::assertGreaterThanOrEqual(1, $report->executedCount());
        self::assertCount(2, $report->errors());
        self::assertTrue($app->make('runtime.reset.flush_failure.after_resetter'));
        self::assertSame('resetter failed', $report->errors()[0]->getMessage());
        self::assertSame('flush scope failed', $report->errors()[1]->getMessage());
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
