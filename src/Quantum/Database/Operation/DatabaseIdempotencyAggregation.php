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
        ];
    }
}
