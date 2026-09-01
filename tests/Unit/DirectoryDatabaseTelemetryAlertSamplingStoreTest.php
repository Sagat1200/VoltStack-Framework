<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\Engine\DirectoryDatabaseTelemetryAlertSamplingStore;

final class DirectoryDatabaseTelemetryAlertSamplingStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-telemetry-alert-sampling-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_persists_occurrences_per_node_and_alert_across_instances(): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'sampling';

        $firstStore = new DirectoryDatabaseTelemetryAlertSamplingStore($directory);
        $secondStore = new DirectoryDatabaseTelemetryAlertSamplingStore($directory);

        self::assertSame(1, $firstStore->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertSame(2, $secondStore->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertSame(1, $secondStore->nextOccurrence('node-b', 'database.sqg_pipeline.optimizer.no_gain'));
    }

    public function test_it_can_reset_a_single_node_scope(): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'sampling';
        $store = new DirectoryDatabaseTelemetryAlertSamplingStore($directory);

        $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain');
        $store->nextOccurrence('node-b', 'database.sqg_pipeline.optimizer.no_gain');

        $store->reset('node-a');

        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertSame(2, $store->nextOccurrence('node-b', 'database.sqg_pipeline.optimizer.no_gain'));
    }

    public function test_it_restarts_occurrence_after_sampling_window_expires(): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'sampling-window';
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };

        $firstStore = new DirectoryDatabaseTelemetryAlertSamplingStore($directory, 60, $clock);
        $secondStore = new DirectoryDatabaseTelemetryAlertSamplingStore($directory, 60, $clock);

        self::assertSame(1, $firstStore->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        $now = $now->modify('+30 seconds');
        self::assertSame(2, $secondStore->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        $now = $now->modify('+61 seconds');
        self::assertSame(1, $firstStore->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
    }

    public function test_it_prunes_expired_alert_records_from_disk_when_node_activity_resumes(): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'sampling-prune';
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };

        $store = new DirectoryDatabaseTelemetryAlertSamplingStore($directory, 60, $clock);

        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertCount(1, $this->jsonFilesForNode($directory, 'node-a'));

        $now = $now->modify('+61 seconds');
        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.wide_search'));

        $jsonFiles = $this->jsonFilesForNode($directory, 'node-a');
        self::assertCount(1, $jsonFiles);
        self::assertStringContainsString(
            hash('sha256', 'database.sqg_pipeline.optimizer.wide_search'),
            $jsonFiles[0],
        );
        self::assertSame(1, $store->metrics()['last_pruned_records'] ?? null);
        self::assertSame(1, $store->metrics()['pruned_records_total'] ?? null);
        self::assertFileDoesNotExist(
            $directory . DIRECTORY_SEPARATOR . 'node-a' . DIRECTORY_SEPARATOR . hash('sha256', 'database.sqg_pipeline.optimizer.no_gain') . '.json',
        );
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
                @rmdir($target);

                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }

    /**
     * @return list<string>
     */
    private function jsonFilesForNode(string $directory, string $nodeId): array
    {
        $files = glob($directory . DIRECTORY_SEPARATOR . $nodeId . DIRECTORY_SEPARATOR . '*.json');

        return is_array($files) ? array_values($files) : [];
    }
}
