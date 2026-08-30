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
