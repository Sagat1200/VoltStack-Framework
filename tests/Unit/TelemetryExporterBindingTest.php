<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\Engine\HttpTelemetryExporter;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use Quantum\Telemetry\Engine\JsonLineTelemetryExporter;
use Quantum\Telemetry\Engine\NullTelemetryExporter;
use VoltStack\Framework\Application;

final class TelemetryExporterBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-telemetry-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_in_memory_exporter_by_default_in_non_production(): void
    {
        $app = new Application($this->basePath);

        $exporter = $app->make(TelemetryExporterInterface::class);

        self::assertInstanceOf(InMemoryTelemetryExporter::class, $exporter);
        self::assertInstanceOf(TelemetryManagerInterface::class, $app->make(TelemetryManagerInterface::class));
    }

    public function test_it_resolves_jsonl_exporter_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('telemetry.exporter', 'jsonl');
        $app->make(ConfigRepository::class)->set('telemetry.jsonl_path', $this->basePath . DIRECTORY_SEPARATOR . 'telemetry.jsonl');

        $exporter = $app->make(TelemetryExporterInterface::class);

        self::assertInstanceOf(JsonLineTelemetryExporter::class, $exporter);
        self::assertSame($this->basePath . DIRECTORY_SEPARATOR . 'telemetry.jsonl', $exporter->filePath());
    }

    public function test_it_resolves_webhook_exporter_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('telemetry.exporter', 'webhook');
        $app->make(ConfigRepository::class)->set('telemetry.webhook_url', 'https://monitoring.internal/voltstack/telemetry');

        $exporter = $app->make(TelemetryExporterInterface::class);

        self::assertInstanceOf(HttpTelemetryExporter::class, $exporter);
        self::assertSame('https://monitoring.internal/voltstack/telemetry', $exporter->endpoint());
    }

    public function test_it_resolves_null_exporter_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('telemetry.exporter', 'null');

        $exporter = $app->make(TelemetryExporterInterface::class);

        self::assertInstanceOf(NullTelemetryExporter::class, $exporter);
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
