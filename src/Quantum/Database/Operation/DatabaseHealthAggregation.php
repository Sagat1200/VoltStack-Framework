<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final class DatabaseHealthAggregation
{
    /**
     * @param list<DatabaseTelemetryReport> $reports
     * @return array<string, mixed>
     */
    public static function aggregate(array $reports): array
    {
        $requestIds = [];
        $tenantIds = [];
        $nodeIds = [];
        $segments = [];
        $oldestAt = null;
        $latestAt = null;
        $summary = [
            'total_operations' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'slow_queries' => 0,
            'remote_replay_challenge' => [
                'observed_operations' => 0,
                'verified' => 0,
                'unavailable' => 0,
                'rejected' => 0,
                'reused_receipts' => 0,
                'cleanup_tombstones' => 0,
                'compatible' => 0,
                'incompatible' => 0,
                'protocols' => [],
                'request_key_ids' => [],
                'response_key_ids' => [],
            ],
            'resource_governance' => array_merge(
                DatabaseTelemetryStore::emptyResourceGovernanceSummary(),
                [
                    'observed_requests' => 0,
                    'by_tenant' => [],
                ],
            ),
        ];
        $health = [
            'closed_segments' => 0,
            'half_open_segments' => 0,
            'open_segments' => 0,
        ];

        foreach ($reports as $report) {
            $requestIds[$report->requestId] = true;
            if ($report->tenantId !== null && $report->tenantId !== '') {
                $tenantIds[$report->tenantId] = true;
            }
            if ($report->nodeId !== null && $report->nodeId !== '') {
                $nodeIds[$report->nodeId] = true;
            }

            $generatedAt = $report->generatedAt;
            if ($oldestAt === null || strcmp($generatedAt, $oldestAt) < 0) {
                $oldestAt = $generatedAt;
            }
            if ($latestAt === null || strcmp($generatedAt, $latestAt) > 0) {
                $latestAt = $generatedAt;
            }

            $reportSummary = $report->summary;
            $summary['total_operations'] += (int) ($reportSummary['total_operations'] ?? 0);
            $summary['completed'] += (int) ($reportSummary['completed'] ?? 0);
            $summary['failed'] += (int) ($reportSummary['failed'] ?? 0);
            $summary['cancelled'] += (int) ($reportSummary['cancelled'] ?? 0);
            $summary['slow_queries'] += (int) ($reportSummary['slow_queries'] ?? 0);
            $reportRemoteReplayChallenge = is_array($reportSummary['remote_replay_challenge'] ?? null)
                ? $reportSummary['remote_replay_challenge']
                : [];
            $summary['remote_replay_challenge']['observed_operations'] += (int) ($reportRemoteReplayChallenge['observed_operations'] ?? 0);
            $summary['remote_replay_challenge']['verified'] += (int) ($reportRemoteReplayChallenge['verified'] ?? 0);
            $summary['remote_replay_challenge']['unavailable'] += (int) ($reportRemoteReplayChallenge['unavailable'] ?? 0);
            $summary['remote_replay_challenge']['rejected'] += (int) ($reportRemoteReplayChallenge['rejected'] ?? 0);
            $summary['remote_replay_challenge']['reused_receipts'] += (int) ($reportRemoteReplayChallenge['reused_receipts'] ?? 0);
            $summary['remote_replay_challenge']['cleanup_tombstones'] += (int) ($reportRemoteReplayChallenge['cleanup_tombstones'] ?? 0);
            $summary['remote_replay_challenge']['compatible'] += (int) ($reportRemoteReplayChallenge['compatible'] ?? 0);
            $summary['remote_replay_challenge']['incompatible'] += (int) ($reportRemoteReplayChallenge['incompatible'] ?? 0);
            self::mergeCountMap(
                $summary['remote_replay_challenge']['protocols'],
                is_array($reportRemoteReplayChallenge['protocols'] ?? null) ? $reportRemoteReplayChallenge['protocols'] : [],
            );
            self::mergeCountMap(
                $summary['remote_replay_challenge']['request_key_ids'],
                is_array($reportRemoteReplayChallenge['request_key_ids'] ?? null) ? $reportRemoteReplayChallenge['request_key_ids'] : [],
            );
            self::mergeCountMap(
                $summary['remote_replay_challenge']['response_key_ids'],
                is_array($reportRemoteReplayChallenge['response_key_ids'] ?? null) ? $reportRemoteReplayChallenge['response_key_ids'] : [],
            );
            $reportResourceGovernance = DatabaseTelemetryStore::normalizeResourceGovernanceSummary(
                is_array($reportSummary['resource_governance'] ?? null) ? $reportSummary['resource_governance'] : [],
            );
            self::mergeResourceGovernanceSummary(
                $summary['resource_governance'],
                $reportResourceGovernance,
            );
            $summary['resource_governance']['observed_requests'] = (int) ($summary['resource_governance']['observed_requests'] ?? 0) + 1;

            if ($report->tenantId !== null && $report->tenantId !== '') {
                $tenantSummary = $summary['resource_governance']['by_tenant'][$report->tenantId] ?? self::emptyTenantResourceGovernanceSummary();
                self::mergeTenantResourceGovernanceSummary($tenantSummary, $reportResourceGovernance);
                $summary['resource_governance']['by_tenant'][$report->tenantId] = $tenantSummary;
            }

            $reportHealth = $report->health;
            $health['closed_segments'] += (int) ($reportHealth['closed_segments'] ?? 0);
            $health['half_open_segments'] += (int) ($reportHealth['half_open_segments'] ?? 0);
            $health['open_segments'] += (int) ($reportHealth['open_segments'] ?? 0);

            $reportSegments = is_array($reportHealth['segments'] ?? null) ? $reportHealth['segments'] : [];
            foreach ($reportSegments as $segment) {
                if (!is_array($segment)) {
                    continue;
                }

                $segmentId = (string) ($segment['segment'] ?? '');
                if ($segmentId !== '') {
                    $segments[$segmentId] = true;
                }
            }
        }

        $summary['resource_governance'] = self::finalizeAggregateResourceGovernanceSummary(
            is_array($summary['resource_governance'] ?? null) ? $summary['resource_governance'] : [],
        );

        return [
            'snapshots' => count($reports),
            'requests' => count($requestIds),
            'tenants' => count($tenantIds),
            'nodes' => count($nodeIds),
            'observed_segments' => count($segments),
            'oldest_generated_at' => $oldestAt,
            'latest_generated_at' => $latestAt,
            'summary' => $summary,
            'health' => $health,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private static function mergeResourceGovernanceSummary(array &$target, array $source): void
    {
        $target['observed_operations'] = (int) ($target['observed_operations'] ?? 0) + (int) ($source['observed_operations'] ?? 0);
        $target['duration_ms_total'] = (int) ($target['duration_ms_total'] ?? 0) + (int) ($source['duration_ms_total'] ?? 0);
        $target['rows_read_total'] = (int) ($target['rows_read_total'] ?? 0) + (int) ($source['rows_read_total'] ?? 0);
        $target['affected_rows_total'] = (int) ($target['affected_rows_total'] ?? 0) + (int) ($source['affected_rows_total'] ?? 0);
        $target['resource_exhausted_operations'] = (int) ($target['resource_exhausted_operations'] ?? 0)
            + (int) ($source['resource_exhausted_operations'] ?? 0);

        $targetBudget = is_array($target['budget'] ?? null) ? $target['budget'] : DatabaseTelemetryStore::emptyResourceGovernanceSummary()['budget'];
        $sourceBudget = is_array($source['budget'] ?? null) ? $source['budget'] : [];
        $targetBudget['timeout_ms_total'] = (int) ($targetBudget['timeout_ms_total'] ?? 0) + (int) ($sourceBudget['timeout_ms_total'] ?? 0);
        $targetBudget['max_rows_total'] = (int) ($targetBudget['max_rows_total'] ?? 0) + (int) ($sourceBudget['max_rows_total'] ?? 0);
        $targetBudget['max_rows_peak'] = max((int) ($targetBudget['max_rows_peak'] ?? 0), (int) ($sourceBudget['max_rows_peak'] ?? 0));
        $targetBudget['max_depth_peak'] = max((int) ($targetBudget['max_depth_peak'] ?? 0), (int) ($sourceBudget['max_depth_peak'] ?? 0));
        $target['budget'] = $targetBudget;

        $targetPressure = is_array($target['pressure'] ?? null) ? $target['pressure'] : DatabaseTelemetryStore::emptyResourceGovernanceSummary()['pressure'];
        $sourcePressure = is_array($source['pressure'] ?? null) ? $source['pressure'] : [];
        foreach (['near_timeout_operations', 'near_row_limit_operations', 'near_depth_limit_operations', 'slow_query_operations', 'resource_exhausted_operations'] as $field) {
            $targetPressure[$field] = (int) ($targetPressure[$field] ?? 0) + (int) ($sourcePressure[$field] ?? 0);
        }

        $targetPressure['_timeout_utilization_pct_sum'] = ((float) ($targetPressure['_timeout_utilization_pct_sum'] ?? 0.0))
            + (((float) ($sourcePressure['timeout_utilization_pct_avg'] ?? 0.0)) * max(0, (int) ($source['observed_operations'] ?? 0)));
        $targetPressure['_row_utilization_pct_sum'] = ((float) ($targetPressure['_row_utilization_pct_sum'] ?? 0.0))
            + (((float) ($sourcePressure['row_utilization_pct_avg'] ?? 0.0)) * max(0, (int) ($source['observed_operations'] ?? 0)));
        $targetPressure['_depth_utilization_pct_sum'] = ((float) ($targetPressure['_depth_utilization_pct_sum'] ?? 0.0))
            + (((float) ($sourcePressure['depth_utilization_pct_avg'] ?? 0.0)) * max(0, (int) ($source['observed_operations'] ?? 0)));
        $target['pressure'] = $targetPressure;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyTenantResourceGovernanceSummary(): array
    {
        return [
            'requests' => 0,
            'observed_operations' => 0,
            'duration_ms_total' => 0,
            'rows_read_total' => 0,
            'affected_rows_total' => 0,
            'resource_exhausted_operations' => 0,
            'pressure' => [
                'near_timeout_operations' => 0,
                'near_row_limit_operations' => 0,
                'near_depth_limit_operations' => 0,
                'slow_query_operations' => 0,
                'timeout_pressure_detected' => false,
                'row_pressure_detected' => false,
                'depth_pressure_detected' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private static function mergeTenantResourceGovernanceSummary(array &$target, array $source): void
    {
        $target['requests'] = (int) ($target['requests'] ?? 0) + 1;
        $target['observed_operations'] = (int) ($target['observed_operations'] ?? 0) + (int) ($source['observed_operations'] ?? 0);
        $target['duration_ms_total'] = (int) ($target['duration_ms_total'] ?? 0) + (int) ($source['duration_ms_total'] ?? 0);
        $target['rows_read_total'] = (int) ($target['rows_read_total'] ?? 0) + (int) ($source['rows_read_total'] ?? 0);
        $target['affected_rows_total'] = (int) ($target['affected_rows_total'] ?? 0) + (int) ($source['affected_rows_total'] ?? 0);
        $target['resource_exhausted_operations'] = (int) ($target['resource_exhausted_operations'] ?? 0)
            + (int) ($source['resource_exhausted_operations'] ?? 0);

        $targetPressure = is_array($target['pressure'] ?? null) ? $target['pressure'] : self::emptyTenantResourceGovernanceSummary()['pressure'];
        $sourcePressure = is_array($source['pressure'] ?? null) ? $source['pressure'] : [];
        foreach (['near_timeout_operations', 'near_row_limit_operations', 'near_depth_limit_operations', 'slow_query_operations'] as $field) {
            $targetPressure[$field] = (int) ($targetPressure[$field] ?? 0) + (int) ($sourcePressure[$field] ?? 0);
        }
        $targetPressure['timeout_pressure_detected'] = ((bool) ($targetPressure['timeout_pressure_detected'] ?? false))
            || ((bool) ($sourcePressure['timeout_pressure_detected'] ?? false));
        $targetPressure['row_pressure_detected'] = ((bool) ($targetPressure['row_pressure_detected'] ?? false))
            || ((bool) ($sourcePressure['row_pressure_detected'] ?? false));
        $targetPressure['depth_pressure_detected'] = ((bool) ($targetPressure['depth_pressure_detected'] ?? false))
            || ((bool) ($sourcePressure['depth_pressure_detected'] ?? false));
        $target['pressure'] = $targetPressure;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private static function finalizeAggregateResourceGovernanceSummary(array $summary): array
    {
        $normalized = DatabaseTelemetryStore::normalizeResourceGovernanceSummary($summary);
        $normalized['observed_requests'] = max(0, (int) ($summary['observed_requests'] ?? 0));
        $globalPressure = is_array($normalized['pressure'] ?? null) ? $normalized['pressure'] : [];
        $globalPressure['timeout_pressure_detected'] = ((int) ($globalPressure['near_timeout_operations'] ?? 0) > 0)
            || ((int) ($globalPressure['slow_query_operations'] ?? 0) > 0);
        $globalPressure['row_pressure_detected'] = ((int) ($globalPressure['near_row_limit_operations'] ?? 0) > 0)
            || ((int) ($globalPressure['resource_exhausted_operations'] ?? 0) > 0);
        $globalPressure['depth_pressure_detected'] = (int) ($globalPressure['near_depth_limit_operations'] ?? 0) > 0;
        $normalized['pressure'] = $globalPressure;

        $byTenant = is_array($summary['by_tenant'] ?? null) ? $summary['by_tenant'] : [];
        $normalized['by_tenant'] = [];
        foreach ($byTenant as $tenantId => $tenantSummary) {
            if (!is_array($tenantSummary)) {
                continue;
            }

            $pressure = is_array($tenantSummary['pressure'] ?? null) ? $tenantSummary['pressure'] : [];
            $normalized['by_tenant'][(string) $tenantId] = [
                'requests' => max(0, (int) ($tenantSummary['requests'] ?? 0)),
                'observed_operations' => max(0, (int) ($tenantSummary['observed_operations'] ?? 0)),
                'duration_ms_total' => max(0, (int) ($tenantSummary['duration_ms_total'] ?? 0)),
                'rows_read_total' => max(0, (int) ($tenantSummary['rows_read_total'] ?? 0)),
                'affected_rows_total' => max(0, (int) ($tenantSummary['affected_rows_total'] ?? 0)),
                'resource_exhausted_operations' => max(0, (int) ($tenantSummary['resource_exhausted_operations'] ?? 0)),
                'pressure' => [
                    'near_timeout_operations' => max(0, (int) ($pressure['near_timeout_operations'] ?? 0)),
                    'near_row_limit_operations' => max(0, (int) ($pressure['near_row_limit_operations'] ?? 0)),
                    'near_depth_limit_operations' => max(0, (int) ($pressure['near_depth_limit_operations'] ?? 0)),
                    'slow_query_operations' => max(0, (int) ($pressure['slow_query_operations'] ?? 0)),
                    'timeout_pressure_detected' => (bool) ($pressure['timeout_pressure_detected'] ?? false),
                    'row_pressure_detected' => (bool) ($pressure['row_pressure_detected'] ?? false),
                    'depth_pressure_detected' => (bool) ($pressure['depth_pressure_detected'] ?? false),
                ],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, int> $target
     * @param array<string, mixed> $source
     */
    private static function mergeCountMap(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $target[$normalizedKey] = (int) ($target[$normalizedKey] ?? 0) + (int) $value;
        }
    }
}
