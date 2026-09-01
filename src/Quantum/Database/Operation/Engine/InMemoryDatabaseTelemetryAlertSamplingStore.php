<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;

final class InMemoryDatabaseTelemetryAlertSamplingStore implements DatabaseTelemetryAlertSamplingStoreInterface
{
    /**
     * @var array<string, array{occurrence:int, updated_at:int}>
     */
    private array $occurrences = [];

    /**
     * @param null|\Closure(): \DateTimeImmutable $clock
     */
    public function __construct(
        private readonly ?int $windowSeconds = 900,
        private readonly ?\Closure $clock = null,
    ) {}

    public function nextOccurrence(string $nodeId, string $alertName): int
    {
        $key = $this->key($nodeId, $alertName);
        $now = $this->now()->getTimestamp();
        $entry = $this->occurrences[$key] ?? null;

        if (is_array($entry) && !$this->isExpired($entry['updated_at'] ?? 0, $now)) {
            $occurrence = max(0, (int) ($entry['occurrence'] ?? 0)) + 1;
        } else {
            $occurrence = 1;
        }

        $this->occurrences[$key] = [
            'occurrence' => $occurrence,
            'updated_at' => $now,
        ];

        return $occurrence;
    }

    public function reset(?string $nodeId = null): void
    {
        if ($nodeId === null || trim($nodeId) === '') {
            $this->occurrences = [];

            return;
        }

        $prefix = trim($nodeId) . '|';
        foreach (array_keys($this->occurrences) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->occurrences[$key]);
            }
        }
    }

    public function metrics(): array
    {
        return [
            'store' => 'in_memory',
            'window_seconds' => $this->windowSeconds,
            'pruned_records_total' => 0,
            'last_pruned_records' => 0,
            'active_keys' => count($this->occurrences),
        ];
    }

    private function key(string $nodeId, string $alertName): string
    {
        $normalizedNodeId = trim($nodeId) !== '' ? trim($nodeId) : 'unknown-node';

        return $normalizedNodeId . '|' . trim($alertName);
    }

    private function isExpired(int $updatedAt, int $now): bool
    {
        if ($this->windowSeconds === null || $this->windowSeconds <= 0) {
            return false;
        }

        return ($now - $updatedAt) >= $this->windowSeconds;
    }

    private function now(): \DateTimeImmutable
    {
        $clock = $this->clock;

        return $clock instanceof \Closure
            ? $clock()
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
