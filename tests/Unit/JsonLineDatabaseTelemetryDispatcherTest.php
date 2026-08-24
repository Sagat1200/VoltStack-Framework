<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\JsonLineDatabaseTelemetryDispatcher;

final class JsonLineDatabaseTelemetryDispatcherTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-observability-jsonl-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_writes_database_telemetry_as_json_line(): void
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'database-events.jsonl';
        $dispatcher = new JsonLineDatabaseTelemetryDispatcher($file, maxBytesPerLine: 4096);

        $dispatcher->dispatch(new DatabaseTelemetryReport(
            requestId: 'req-1',
            tenantId: 'tenant-a',
            traceId: 'trace-1',
            generatedAt: '2026-08-24T12:00:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 1,
                'latest' => [
                    ['logical_target' => 'users'],
                ],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
        ));

        self::assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $lines[0], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('database_telemetry', $payload['type']);
        self::assertSame('req-1', $payload['payload']['request_id']);
        self::assertSame('tenant-a', $payload['payload']['tenant_id']);
        self::assertSame(2, $payload['payload']['summary']['total_operations']);
        self::assertSame(1, $payload['payload']['health']['closed_segments']);
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
