<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\JsonFileDatabaseHealthStore;

final class JsonFileDatabaseHealthStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-health-json-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_persists_and_reads_latest_health_report(): void
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'database-health.json';
        $store = new JsonFileDatabaseHealthStore($file);
        $report = new DatabaseTelemetryReport(
            requestId: 'req-1',
            tenantId: 'tenant-a',
            traceId: 'trace-1',
            generatedAt: '2026-08-24T12:00:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
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
            nodeId: 'node-a',
        );

        $store->persist($report);
        $loaded = $store->latest();

        self::assertFileExists($file);
        self::assertInstanceOf(DatabaseTelemetryReport::class, $loaded);
        self::assertSame('req-1', $loaded->requestId);
        self::assertSame('tenant-a', $loaded->tenantId);
        self::assertSame('node-a', $loaded->nodeId);
        self::assertSame(2, $loaded->summary['total_operations']);
        self::assertSame(1, $loaded->health['closed_segments']);
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
