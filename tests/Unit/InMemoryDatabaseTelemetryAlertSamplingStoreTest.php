<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryAlertSamplingStore;

final class InMemoryDatabaseTelemetryAlertSamplingStoreTest extends TestCase
{
    public function test_it_restarts_occurrence_after_sampling_window_expires(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };

        $store = new InMemoryDatabaseTelemetryAlertSamplingStore(60, $clock);

        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        $now = $now->modify('+30 seconds');
        self::assertSame(2, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        $now = $now->modify('+61 seconds');
        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
    }

    public function test_it_keeps_independent_windows_per_node(): void
    {
        $now = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $clock = static function () use (&$now): \DateTimeImmutable {
            return $now;
        };

        $store = new InMemoryDatabaseTelemetryAlertSamplingStore(60, $clock);

        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertSame(1, $store->nextOccurrence('node-b', 'database.sqg_pipeline.optimizer.no_gain'));
        $now = $now->modify('+61 seconds');
        self::assertSame(1, $store->nextOccurrence('node-a', 'database.sqg_pipeline.optimizer.no_gain'));
        self::assertSame(1, $store->nextOccurrence('node-b', 'database.sqg_pipeline.optimizer.no_gain'));
    }
}
