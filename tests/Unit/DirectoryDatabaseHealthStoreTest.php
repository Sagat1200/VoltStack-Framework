<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\DirectoryDatabaseHealthStore;

final class DirectoryDatabaseHealthStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-health-directory-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_persists_latest_snapshot_per_node_and_aggregates_directory(): void
    {
        $store = new DirectoryDatabaseHealthStore($this->basePath . DIRECTORY_SEPARATOR . 'health-nodes');

        $store->persist(new DatabaseTelemetryReport(
            requestId: 'req-1',
            tenantId: 'tenant-a',
            traceId: 'trace-1',
            generatedAt: '2026-08-24T12:00:00+00:00',
            summary: [
                'total_operations' => 1,
                'completed' => 1,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'latest' => [],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [
                    ['segment' => 'primary|sqlite|raw_query|users'],
                ],
            ],
            nodeId: 'node-a',
        ));
        $store->persist(new DatabaseTelemetryReport(
            requestId: 'req-2',
            tenantId: 'tenant-b',
            traceId: 'trace-2',
            generatedAt: '2026-08-24T12:01:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 1,
                'failed' => 1,
                'cancelled' => 0,
                'slow_queries' => 1,
                'latest' => [],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 0,
                'half_open_segments' => 0,
                'open_segments' => 1,
                'segments' => [
                    ['segment' => 'primary|sqlite|raw_query|posts'],
                ],
            ],
            nodeId: 'node-b',
        ));
        $store->persist(new DatabaseTelemetryReport(
            requestId: 'req-3',
            tenantId: 'tenant-a',
            traceId: 'trace-3',
            generatedAt: '2026-08-24T12:02:00+00:00',
            summary: [
                'total_operations' => 3,
                'completed' => 3,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'latest' => [],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [
                    ['segment' => 'primary|sqlite|raw_query|users'],
                ],
            ],
            nodeId: 'node-a',
        ));

        $recent = $store->recent(10);
        $aggregate = $store->aggregate(10);

        self::assertCount(2, $recent);
        self::assertSame('req-3', $store->latest()?->requestId);
        self::assertSame(2, $aggregate['snapshots']);
        self::assertSame(2, $aggregate['requests']);
        self::assertSame(2, $aggregate['nodes']);
        self::assertSame(2, $aggregate['tenants']);
        self::assertSame(2, $aggregate['observed_segments']);
        self::assertSame(5, $aggregate['summary']['total_operations']);
        self::assertSame(4, $aggregate['summary']['completed']);
        self::assertSame(1, $aggregate['summary']['failed']);
        self::assertSame(1, $aggregate['health']['open_segments']);
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
