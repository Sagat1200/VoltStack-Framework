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
}