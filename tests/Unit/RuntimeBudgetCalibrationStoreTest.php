<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\BuildManifest;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStore;

final class RuntimeBudgetCalibrationStoreTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-budget-store-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_it_publishes_and_reads_the_current_runtime_budget_calibration(): void
    {
        $store = new RuntimeBudgetCalibrationStore(
            manifest: new BuildManifest($this->storageRoot),
            storageRoot: $this->storageRoot,
        );
        $artifact = $store->publish($this->report());

        $store->activateGeneration($artifact->generationId());
        $current = $store->currentArtifact();

        self::assertNotNull($current);
        self::assertSame($artifact->generationId(), $current->generationId());
        self::assertSame('frankenphp', $current->driver());
        self::assertSame('release', $current->profile());
        self::assertSame(42.5, $current->recommendedBudget()->totalMaximumMs());
        self::assertSame(21.25, $current->recommendedBudget()->requestMaximumMs());
        self::assertSame('published-calibration', $current->recommendedBudget()->source());
    }

    private function report(): RuntimeBudgetCalibrationReport
    {
        return new RuntimeBudgetCalibrationReport(
            driver: 'frankenphp',
            profile: 'release',
            requestDefinitions: ['GET:/ok', 'GET:/health'],
            warmupIterations: 1,
            measuredIterations: 3,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('frankenphp', 50.0, 25.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('frankenphp', 42.5, 21.25, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 20.0, 'max_request_duration_ms' => 10.0, 'request_count' => 2],
                ['iteration' => 2, 'total_duration_ms' => 34.0, 'max_request_duration_ms' => 17.0, 'request_count' => 2],
                ['iteration' => 3, 'total_duration_ms' => 30.0, 'max_request_duration_ms' => 14.0, 'request_count' => 2],
            ],
            failures: [],
            totalStats: ['min' => 20.0, 'avg' => 28.0, 'p95' => 34.0, 'max' => 34.0],
            requestStats: ['min' => 10.0, 'avg' => 13.666, 'p95' => 17.0, 'max' => 17.0],
        );
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
