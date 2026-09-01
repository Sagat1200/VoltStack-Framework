<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\DirectoryDatabaseTelemetryAlertSamplingStore;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalAlertSampler;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalMapper;

final class DatabaseTelemetrySignalAlertSamplerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-telemetry-sampler-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_samples_repeated_sqg_warning_alerts_and_keeps_periodic_visibility(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper();
        $sampler = new DatabaseTelemetrySignalAlertSampler([
            'database.sqg_pipeline.optimizer.wide_search' => 3,
            'database.sqg_pipeline.optimizer.no_gain' => 3,
            'database.sqg_pipeline.join_reorder.no_gain' => 3,
        ]);

        $first = $sampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));
        $second = $sampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));
        $third = $sampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));

        self::assertCount(3, $first->alerts);
        self::assertSame(3, $first->alerts[0]['context']['sampling_every'] ?? null);
        self::assertSame(1, $first->alerts[0]['context']['sampling_occurrence'] ?? null);
        self::assertSame('custom', $first->attributes['alert_sampling']['profile'] ?? null);
        self::assertSame('in_memory', $first->attributes['alert_sampling']['store'] ?? null);
        self::assertSame(3, $first->attributes['alert_sampling']['visible_total'] ?? null);
        self::assertSame(3, $first->attributes['alert_sampling']['cumulative_visible_total'] ?? null);

        self::assertSame([], $second->alerts);
        self::assertSame(3, $second->attributes['alert_sampling']['suppressed_total'] ?? null);
        self::assertSame(3, $second->attributes['alert_sampling']['cumulative_visible_total'] ?? null);
        self::assertSame(3, $second->attributes['alert_sampling']['cumulative_suppressed_total'] ?? null);
        self::assertSame(
            1,
            $second->attributes['alert_sampling']['suppressed_alerts']['database.sqg_pipeline.optimizer.wide_search'] ?? null,
        );

        self::assertCount(3, $third->alerts);
        self::assertSame(3, $third->alerts[0]['context']['sampling_occurrence'] ?? null);
    }

    public function test_it_does_not_sample_high_or_critical_sqg_alerts(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper(
            sqgPipelineAlertSeverities: [
                'database.sqg_pipeline.optimizer.wide_search' => 'high',
                'database.sqg_pipeline.optimizer.no_gain' => 'critical',
                'database.sqg_pipeline.join_reorder.no_gain' => 'high',
            ],
        );
        $sampler = new DatabaseTelemetrySignalAlertSampler([
            'database.sqg_pipeline.optimizer.wide_search' => 5,
            'database.sqg_pipeline.optimizer.no_gain' => 5,
            'database.sqg_pipeline.join_reorder.no_gain' => 5,
        ]);

        $first = $sampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));
        $second = $sampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));

        self::assertCount(3, $first->alerts);
        self::assertCount(3, $second->alerts);
        self::assertSame('high', $second->alerts[0]['severity']);
        self::assertSame('critical', $second->alerts[1]['severity']);
        self::assertArrayNotHasKey('alert_sampling', $second->attributes);
    }

    public function test_it_persists_sampling_occurrences_across_sampler_instances_when_store_is_directory_based(): void
    {
        $mapper = new DatabaseTelemetrySignalMapper();
        $samplingDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'sampling';
        $firstSampler = new DatabaseTelemetrySignalAlertSampler([
            'database.sqg_pipeline.optimizer.wide_search' => 3,
            'database.sqg_pipeline.optimizer.no_gain' => 3,
            'database.sqg_pipeline.join_reorder.no_gain' => 3,
        ], new DirectoryDatabaseTelemetryAlertSamplingStore($samplingDirectory));
        $secondSampler = new DatabaseTelemetrySignalAlertSampler([
            'database.sqg_pipeline.optimizer.wide_search' => 3,
            'database.sqg_pipeline.optimizer.no_gain' => 3,
            'database.sqg_pipeline.join_reorder.no_gain' => 3,
        ], new DirectoryDatabaseTelemetryAlertSamplingStore($samplingDirectory));

        $first = $firstSampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));
        $second = $secondSampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));
        $third = $secondSampler->apply($mapper->map($this->sqgWideSearchNoGainReport()));

        self::assertCount(3, $first->alerts);
        self::assertSame([], $second->alerts);
        self::assertCount(3, $third->alerts);
        self::assertSame(3, $third->alerts[0]['context']['sampling_occurrence'] ?? null);
        self::assertSame('directory', $third->attributes['alert_sampling']['store'] ?? null);
        self::assertSame(0, $third->attributes['alert_sampling']['pruned_records_total'] ?? null);
    }

    private function sqgWideSearchNoGainReport(): DatabaseTelemetryReport
    {
        return new DatabaseTelemetryReport(
            requestId: 'req-db-sampler',
            tenantId: 'tenant-a',
            traceId: 'trace-db-sampler',
            generatedAt: '2026-08-31T20:00:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'remote_replay_challenge' => [],
                'sqg_pipeline' => [
                    'observed_operations' => 2,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 150.0,
                    'estimated_cost_avg' => 75.0,
                    'estimated_cost_min' => 70.0,
                    'estimated_cost_max' => 80.0,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 8,
                    'candidate_count_avg' => 4.0,
                    'candidate_count_max' => 4,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 2],
                    'selected_candidates' => ['candidate:predicate_normalization_v1+join_reorder_v1' => 2],
                    'planner_logical_roots' => ['sort' => 2],
                    'planner_physical_roots' => ['sort_materialize' => 2],
                ],
                'latest' => [],
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
