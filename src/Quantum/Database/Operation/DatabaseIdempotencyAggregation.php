<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final class DatabaseIdempotencyAggregation
{
    /**
     * @param list<DatabaseIdempotencyRecord> $records
     * @return array<string, mixed>
     */
    public static function aggregate(array $records): array
    {
        $requests = [];
        $connections = [];
        $targets = [];
        $nodes = [];
        $statuses = [
            'pending' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        $expiredPending = 0;
        $oldestAt = null;
        $latestAt = null;
        $replaySupport = [
            'persisted_summary' => 0,
            'legacy_reconstructed' => 0,
            'unknown' => 0,
        ];
        $legacyReplayWarningCandidates = 0;
        $confirmations = [
            'with_confirmation' => 0,
            'without_confirmation' => 0,
            'summary_version_1' => 0,
            'legacy_without_summary' => 0,
        ];

        foreach ($records as $record) {
            $requests[$record->requestId] = true;
            $connections[$record->connectionName] = true;
            $targets[$record->logicalTarget] = true;
            if ($record->nodeId !== null && $record->nodeId !== '') {
                $nodes[$record->nodeId] = true;
            }

            if (!array_key_exists($record->status, $statuses)) {
                $statuses[$record->status] = 0;
            }
            $statuses[$record->status]++;
            if ($record->isExpired()) {
                $expiredPending++;
            }
            if ($record->confirmation !== []) {
                $confirmations['with_confirmation']++;

                $reproducibility = self::resolveReplayReproducibility($record->confirmation);
                if (!array_key_exists($reproducibility, $replaySupport)) {
                    $replaySupport['unknown']++;
                } else {
                    $replaySupport[$reproducibility]++;
                }

                if (isset($record->confirmation['summary_version']) && (int) $record->confirmation['summary_version'] === 1) {
                    $confirmations['summary_version_1']++;
                }

                if ($reproducibility === 'legacy_reconstructed') {
                    $legacyReplayWarningCandidates++;
                    $confirmations['legacy_without_summary']++;
                }
            } else {
                $confirmations['without_confirmation']++;
            }

            if ($oldestAt === null || strcmp($record->createdAt, $oldestAt) < 0) {
                $oldestAt = $record->createdAt;
            }
            if ($latestAt === null || strcmp($record->createdAt, $latestAt) > 0) {
                $latestAt = $record->createdAt;
            }
        }

        return [
            'records' => count($records),
            'requests' => count($requests),
            'connections' => count($connections),
            'logical_targets' => count($targets),
            'nodes' => count($nodes),
            'oldest_created_at' => $oldestAt,
            'latest_created_at' => $latestAt,
            'statuses' => $statuses,
            'expired_pending' => $expiredPending,
            'confirmations' => $confirmations,
            'replay_support' => $replaySupport,
            'legacy_replay_warning_candidates' => $legacyReplayWarningCandidates,
        ];
    }

    /**
     * @param array<string, mixed> $confirmation
     */
    private static function resolveReplayReproducibility(array $confirmation): string
    {
        $value = $confirmation['replay_reproducibility'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return is_array($confirmation['result_summary'] ?? null)
            ? 'persisted_summary'
            : 'legacy_reconstructed';
    }
}