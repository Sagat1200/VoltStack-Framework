<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\TelemetrySignal;

final class DatabaseTelemetrySignalMapper
{
    public function map(DatabaseTelemetryReport $report): TelemetrySignal
    {
        return new TelemetrySignal(
            name: 'database_telemetry',
            type: 'report',
            source: 'database',
            occurredAt: $report->generatedAt,
            payload: $report->toArray(),
            attributes: [
                'summary' => [
                    'total_operations' => (int) ($report->summary['total_operations'] ?? 0),
                    'completed' => (int) ($report->summary['completed'] ?? 0),
                    'failed' => (int) ($report->summary['failed'] ?? 0),
                    'cancelled' => (int) ($report->summary['cancelled'] ?? 0),
                    'slow_queries' => (int) ($report->summary['slow_queries'] ?? 0),
                ],
                'health' => [
                    'total_segments' => (int) ($report->health['total_segments'] ?? 0),
                    'open_segments' => (int) ($report->health['open_segments'] ?? 0),
                    'half_open_segments' => (int) ($report->health['half_open_segments'] ?? 0),
                    'closed_segments' => (int) ($report->health['closed_segments'] ?? 0),
                ],
                'sqg_pipeline' => [
                    'observed_operations' => (int) ($report->summary['sqg_pipeline']['observed_operations'] ?? 0),
                    'join_reorder_selected' => (int) ($report->summary['sqg_pipeline']['join_reorder_selected'] ?? 0),
                    'optimizer_strategies' => is_array($report->summary['sqg_pipeline']['optimizer_strategies'] ?? null)
                        ? $report->summary['sqg_pipeline']['optimizer_strategies']
                        : [],
                    'selected_candidates' => is_array($report->summary['sqg_pipeline']['selected_candidates'] ?? null)
                        ? $report->summary['sqg_pipeline']['selected_candidates']
                        : [],
                    'planner_logical_roots' => is_array($report->summary['sqg_pipeline']['planner_logical_roots'] ?? null)
                        ? $report->summary['sqg_pipeline']['planner_logical_roots']
                        : [],
                    'planner_physical_roots' => is_array($report->summary['sqg_pipeline']['planner_physical_roots'] ?? null)
                        ? $report->summary['sqg_pipeline']['planner_physical_roots']
                        : [],
                ],
            ],
            alerts: $this->buildAlerts($report),
            requestId: $report->requestId,
            tenantId: $report->tenantId,
            traceId: $report->traceId,
            nodeId: $report->nodeId,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAlerts(DatabaseTelemetryReport $report): array
    {
        $summary = is_array($report->summary['remote_replay_challenge'] ?? null)
            ? $report->summary['remote_replay_challenge']
            : [];

        $alerts = [];
        $incompatible = (int) ($summary['incompatible'] ?? 0);
        if ($incompatible > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.incompatible',
                'severity' => 'critical',
                'count' => $incompatible,
                'context' => [
                    'protocols' => is_array($summary['protocols'] ?? null) ? $summary['protocols'] : [],
                    'request_key_ids' => is_array($summary['request_key_ids'] ?? null) ? $summary['request_key_ids'] : [],
                    'response_key_ids' => is_array($summary['response_key_ids'] ?? null) ? $summary['response_key_ids'] : [],
                ],
            ];
        }

        $rejected = (int) ($summary['rejected'] ?? 0);
        if ($rejected > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.rejected',
                'severity' => 'high',
                'count' => $rejected,
                'context' => [
                    'protocols' => is_array($summary['protocols'] ?? null) ? $summary['protocols'] : [],
                ],
            ];
        }

        $unavailable = (int) ($summary['unavailable'] ?? 0);
        if ($unavailable > 0) {
            $alerts[] = [
                'name' => 'database.remote_replay_challenge.unavailable',
                'severity' => 'warning',
                'count' => $unavailable,
                'context' => [
                    'observed_operations' => (int) ($summary['observed_operations'] ?? 0),
                ],
            ];
        }

        return $alerts;
    }
}
