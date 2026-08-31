<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\TelemetrySignal;

final class DatabaseTelemetrySignalMapper
{
    /**
     * @param array<string, mixed> $sqgPipelineAlertThresholds
     * @param array<string, mixed> $sqgPipelineAlertSeverities
     */
    public function __construct(
        private readonly array $sqgPipelineAlertThresholds = [],
        private readonly array $sqgPipelineAlertSeverities = [],
    ) {}

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
                    'estimated_cost_total' => (float) ($report->summary['sqg_pipeline']['estimated_cost_total'] ?? 0.0),
                    'estimated_cost_avg' => (float) ($report->summary['sqg_pipeline']['estimated_cost_avg'] ?? 0.0),
                    'estimated_cost_min' => $report->summary['sqg_pipeline']['estimated_cost_min'] ?? null,
                    'estimated_cost_max' => $report->summary['sqg_pipeline']['estimated_cost_max'] ?? null,
                    'cost_delta_vs_baseline_total' => (float) ($report->summary['sqg_pipeline']['cost_delta_vs_baseline_total'] ?? 0.0),
                    'cost_delta_vs_baseline_avg' => (float) ($report->summary['sqg_pipeline']['cost_delta_vs_baseline_avg'] ?? 0.0),
                    'cost_delta_vs_baseline_max' => (float) ($report->summary['sqg_pipeline']['cost_delta_vs_baseline_max'] ?? 0.0),
                    'candidate_count_total' => (int) ($report->summary['sqg_pipeline']['candidate_count_total'] ?? 0),
                    'candidate_count_avg' => (float) ($report->summary['sqg_pipeline']['candidate_count_avg'] ?? 0.0),
                    'candidate_count_max' => (int) ($report->summary['sqg_pipeline']['candidate_count_max'] ?? 0),
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
                    'join_reorder_signatures' => is_array($report->summary['sqg_pipeline']['join_reorder_signatures'] ?? null)
                        ? $report->summary['sqg_pipeline']['join_reorder_signatures']
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
        $sqgPipeline = is_array($report->summary['sqg_pipeline'] ?? null)
            ? $report->summary['sqg_pipeline']
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

        $sqgObserved = (int) ($sqgPipeline['observed_operations'] ?? 0);
        $candidateCountTotal = (int) ($sqgPipeline['candidate_count_total'] ?? 0);
        $candidateCountAvg = (float) ($sqgPipeline['candidate_count_avg'] ?? 0.0);
        $candidateCountMax = (int) ($sqgPipeline['candidate_count_max'] ?? 0);
        $costDeltaTotal = (float) ($sqgPipeline['cost_delta_vs_baseline_total'] ?? 0.0);
        $joinReorderSelected = (int) ($sqgPipeline['join_reorder_selected'] ?? 0);
        $wideSearchCandidateCountMax = $this->intThreshold('wide_search_candidate_count_max', 4);
        $wideSearchCandidateCountAvg = $this->floatThreshold('wide_search_candidate_count_avg', 3.0);
        $noGainCostDeltaMax = $this->floatThreshold('no_gain_cost_delta_max', 0.0);

        if ($candidateCountMax >= $wideSearchCandidateCountMax || $candidateCountAvg >= $wideSearchCandidateCountAvg) {
            $alerts[] = [
                'name' => 'database.sqg_pipeline.optimizer.wide_search',
                'severity' => $this->sqgAlertSeverity('database.sqg_pipeline.optimizer.wide_search', 'warning'),
                'count' => $candidateCountMax,
                'context' => [
                    'observed_operations' => $sqgObserved,
                    'candidate_count_total' => $candidateCountTotal,
                    'candidate_count_avg' => $candidateCountAvg,
                    'candidate_count_max' => $candidateCountMax,
                    'threshold_candidate_count_max' => $wideSearchCandidateCountMax,
                    'threshold_candidate_count_avg' => $wideSearchCandidateCountAvg,
                    'selected_candidates' => is_array($sqgPipeline['selected_candidates'] ?? null)
                        ? $sqgPipeline['selected_candidates']
                        : [],
                ],
            ];
        }

        if ($sqgObserved > 0 && $candidateCountTotal > $sqgObserved && $costDeltaTotal <= $noGainCostDeltaMax) {
            $alerts[] = [
                'name' => 'database.sqg_pipeline.optimizer.no_gain',
                'severity' => $this->sqgAlertSeverity('database.sqg_pipeline.optimizer.no_gain', 'warning'),
                'count' => $candidateCountTotal,
                'context' => [
                    'observed_operations' => $sqgObserved,
                    'candidate_count_total' => $candidateCountTotal,
                    'candidate_count_avg' => $candidateCountAvg,
                    'cost_delta_vs_baseline_total' => $costDeltaTotal,
                    'threshold_cost_delta_max' => $noGainCostDeltaMax,
                    'optimizer_strategies' => is_array($sqgPipeline['optimizer_strategies'] ?? null)
                        ? $sqgPipeline['optimizer_strategies']
                        : [],
                ],
            ];
        }

        if ($joinReorderSelected > 0 && $costDeltaTotal <= $noGainCostDeltaMax) {
            $alerts[] = [
                'name' => 'database.sqg_pipeline.join_reorder.no_gain',
                'severity' => $this->sqgAlertSeverity('database.sqg_pipeline.join_reorder.no_gain', 'warning'),
                'count' => $joinReorderSelected,
                'context' => [
                    'observed_operations' => $sqgObserved,
                    'join_reorder_selected' => $joinReorderSelected,
                    'join_reorder_signatures' => is_array($sqgPipeline['join_reorder_signatures'] ?? null)
                        ? $sqgPipeline['join_reorder_signatures']
                        : [],
                    'cost_delta_vs_baseline_total' => $costDeltaTotal,
                    'threshold_cost_delta_max' => $noGainCostDeltaMax,
                ],
            ];
        }

        return $alerts;
    }

    private function intThreshold(string $key, int $default): int
    {
        $value = $this->sqgPipelineAlertThresholds[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function floatThreshold(string $key, float $default): float
    {
        $value = $this->sqgPipelineAlertThresholds[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    private function sqgAlertSeverity(string $alertName, string $default): string
    {
        $severity = strtolower(trim((string) ($this->sqgPipelineAlertSeverities[$alertName] ?? '')));

        return in_array($severity, ['info', 'warning', 'high', 'critical'], true)
            ? $severity
            : $default;
    }
}