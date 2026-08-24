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
}
