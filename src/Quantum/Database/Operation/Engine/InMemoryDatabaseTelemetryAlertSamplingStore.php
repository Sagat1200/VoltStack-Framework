<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;

final class InMemoryDatabaseTelemetryAlertSamplingStore implements DatabaseTelemetryAlertSamplingStoreInterface
{
    /**
     * @var array<string, int>
     */
    private array $occurrences = [];

    public function nextOccurrence(string $nodeId, string $alertName): int
    {
        $key = $this->key($nodeId, $alertName);
        $occurrence = ($this->occurrences[$key] ?? 0) + 1;
        $this->occurrences[$key] = $occurrence;

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

    private function key(string $nodeId, string $alertName): string
    {
        $normalizedNodeId = trim($nodeId) !== '' ? trim($nodeId) : 'unknown-node';

        return $normalizedNodeId . '|' . trim($alertName);
    }
}
