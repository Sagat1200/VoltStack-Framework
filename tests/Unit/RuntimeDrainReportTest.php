<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use VoltStack\Runtime\Evidence\RuntimeDrainReport;

/**
 * Pruebas para RuntimeDrainReport: serialización, round-trip fromArray/toArray,
 * estados válidos/inválidos y flags de graceful drain.
 */
final class RuntimeDrainReportTest extends TestCase
{
    public function test_to_array_exposes_expected_keys(): void
    {
        $report = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win-frankenphp-x64',
            profile: 'release',
            executedAt: 1728000000,
            drainStrategy: RuntimeDrainReport::STRATEGY_SIGTERM,
            outcome: RuntimeDrainReport::OUTCOME_SUCCESS,
            graceful: true,
            durationMs: 1234.5,
            timeoutMs: 30000,
            activeRequestsBefore: 12,
            activeRequestsAfter: 0,
            errorMessage: null,
            metadata: ['pid' => 1234],
        );

        $array = $report->toArray();

        $this->assertSame('frankenphp', $array['driver']);
        $this->assertSame('win-frankenphp-x64', $array['platform']);
        $this->assertSame('release', $array['profile']);
        $this->assertSame(1728000000, $array['executed_at']);
        $this->assertSame('sigterm', $array['drain_strategy']);
        $this->assertSame('success', $array['outcome']);
        $this->assertTrue($array['graceful']);
        $this->assertSame(1234.5, $array['duration_ms']);
        $this->assertSame(30000, $array['timeout_ms']);
        $this->assertSame(12, $array['active_requests_before']);
        $this->assertSame(0, $array['active_requests_after']);
        $this->assertNull($array['error_message']);
        $this->assertTrue($array['valid']);
        $this->assertTrue($array['drained_gracefully']);
        $this->assertSame(['pid' => 1234], $array['metadata']);
    }

    public function test_from_array_round_trip_preserves_data(): void
    {
        $payload = [
            'driver' => 'roadrunner',
            'platform' => 'linux-roadrunner-x64',
            'profile' => 'staging',
            'executed_at' => 1728001234,
            'drain_strategy' => 'rr-reset',
            'outcome' => 'success',
            'graceful' => true,
            'duration_ms' => 456.7,
            'timeout_ms' => 15000,
            'active_requests_before' => 3,
            'active_requests_after' => 0,
            'error_message' => null,
            'metadata' => ['pid' => 999, 'runner_version' => '2024.1'],
        ];

        $report = RuntimeDrainReport::fromArray($payload);
        $roundTrip = $report->toArray();

        $this->assertSame($payload['driver'], $roundTrip['driver']);
        $this->assertSame($payload['platform'], $roundTrip['platform']);
        $this->assertSame($payload['profile'], $roundTrip['profile']);
        $this->assertSame($payload['executed_at'], $roundTrip['executed_at']);
        $this->assertSame($payload['drain_strategy'], $roundTrip['drain_strategy']);
        $this->assertSame($payload['outcome'], $roundTrip['outcome']);
        $this->assertSame($payload['duration_ms'], $roundTrip['duration_ms']);
        $this->assertSame($payload['timeout_ms'], $roundTrip['timeout_ms']);
        $this->assertSame($payload['active_requests_before'], $roundTrip['active_requests_before']);
        $this->assertSame($payload['active_requests_after'], $roundTrip['active_requests_after']);
        $this->assertSame($payload['metadata'], $roundTrip['metadata']);
    }

    public function test_from_array_applies_defaults_for_missing_fields(): void
    {
        $report = RuntimeDrainReport::fromArray(['driver' => 'sapi', 'platform' => 'local']);

        $this->assertSame('sapi', $report->driver());
        $this->assertSame('release', $report->profile());
        $this->assertSame(RuntimeDrainReport::STRATEGY_SIGTERM, $report->drainStrategy());
        $this->assertSame(RuntimeDrainReport::OUTCOME_SUCCESS, $report->outcome());
        $this->assertTrue($report->graceful());
        $this->assertNull($report->durationMs());
        $this->assertSame([], $report->metadata());
    }

    public function test_valid_returns_false_for_unknown_outcome(): void
    {
        $report = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: 'bogus',
            durationMs: 100.0,
        );

        $this->assertFalse($report->valid());
    }

    public function test_valid_requires_duration_for_success_outcome(): void
    {
        $reportWithoutDuration = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: RuntimeDrainReport::OUTCOME_SUCCESS,
            durationMs: null,
        );

        $this->assertFalse($reportWithoutDuration->valid());

        $reportWithDuration = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: RuntimeDrainReport::OUTCOME_SUCCESS,
            durationMs: 100.0,
        );

        $this->assertTrue($reportWithDuration->valid());
    }

    public function test_force_kill_outcome_is_valid_and_not_graceful(): void
    {
        $report = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: RuntimeDrainReport::OUTCOME_FORCE_KILL,
            graceful: false,
            errorMessage: 'timeout exceeded',
        );

        $this->assertTrue($report->valid());
        $this->assertFalse($report->drainedGracefully());
        $this->assertFalse($report->graceful());
    }

    public function test_drained_gracefully_true_only_when_success_and_graceful(): void
    {
        $successGraceful = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: RuntimeDrainReport::OUTCOME_SUCCESS,
            graceful: true,
            durationMs: 100.0,
        );
        $this->assertTrue($successGraceful->drainedGracefully());

        $successNotGraceful = new RuntimeDrainReport(
            driver: 'frankenphp',
            platform: 'win',
            outcome: RuntimeDrainReport::OUTCOME_SUCCESS,
            graceful: false,
            durationMs: 100.0,
        );
        $this->assertFalse($successNotGraceful->drainedGracefully());
    }

    public function test_notes_include_driver_outcome_and_error(): void
    {
        $report = new RuntimeDrainReport(
            driver: 'openswoole',
            platform: 'linux-openswoole-x64',
            profile: 'dev',
            drainStrategy: RuntimeDrainReport::STRATEGY_SWOOLE_SHUTDOWN,
            outcome: RuntimeDrainReport::OUTCOME_ERROR,
            graceful: false,
            durationMs: 0.0,
            errorMessage: 'process not found',
        );

        $notes = $report->notes();

        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('openswoole', $notes[0]);
        $this->assertStringContainsString('error', $notes[0]);
        $this->assertStringContainsString('process not found', implode("\n", $notes));
    }

    public function test_driver_is_normalized_to_lowercase(): void
    {
        $report = new RuntimeDrainReport(driver: 'FRANKENPHP', platform: 'WIN');

        $this->assertSame('frankenphp', $report->driver());
        $this->assertSame('WIN', $report->platform());
    }

    public function test_platform_falls_back_to_unspecified_when_empty(): void
    {
        $report = new RuntimeDrainReport(driver: 'frankenphp', platform: '');

        $this->assertSame('unspecified', $report->platform());
    }
}
