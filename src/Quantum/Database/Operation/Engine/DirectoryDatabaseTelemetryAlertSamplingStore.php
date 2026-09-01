<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;

final class DirectoryDatabaseTelemetryAlertSamplingStore implements DatabaseTelemetryAlertSamplingStoreInterface
{
    private int $prunedRecordsTotal = 0;
    private int $lastPrunedRecords = 0;

    /**
     * @param null|\Closure(): \DateTimeImmutable $clock
     */
    public function __construct(
        private readonly string $directoryPath,
        private readonly ?int $windowSeconds = 900,
        private readonly ?\Closure $clock = null,
    ) {}

    public function nextOccurrence(string $nodeId, string $alertName): int
    {
        $normalizedNodeId = $this->normalizeNodeId($nodeId);
        $normalizedAlertName = trim($alertName);
        $this->pruneExpiredAlertsForNode($normalizedNodeId, $normalizedAlertName);

        return $this->withAlertLock($normalizedNodeId, $normalizedAlertName, function () use ($normalizedNodeId, $normalizedAlertName): int {
            $filePath = $this->filePathForAlert($normalizedNodeId, $normalizedAlertName);
            $now = $this->now();
            $occurrence = 1;

            if (is_file($filePath)) {
                $payload = $this->readPayload($filePath);
                if (!$this->isExpired($payload, $now)) {
                    $occurrence = max(0, (int) ($payload['occurrence'] ?? 0)) + 1;
                }
            }

            $this->writePayload($filePath, [
                'node_id' => $normalizedNodeId,
                'alert_name' => $normalizedAlertName,
                'occurrence' => $occurrence,
                'updated_at' => $now->format(\DATE_ATOM),
            ]);

            return $occurrence;
        });
    }

    public function reset(?string $nodeId = null): void
    {
        if (!is_dir($this->directoryPath)) {
            return;
        }

        if ($nodeId === null || trim($nodeId) === '') {
            $this->deleteDirectoryContents($this->directoryPath);

            return;
        }

        $nodeDirectory = $this->nodeDirectory($this->normalizeNodeId($nodeId));
        if (!is_dir($nodeDirectory)) {
            return;
        }

        $this->deleteDirectoryContents($nodeDirectory);
        @rmdir($nodeDirectory);
    }

    public function directoryPath(): string
    {
        return $this->directoryPath;
    }

    public function metrics(): array
    {
        return [
            'store' => 'directory',
            'window_seconds' => $this->windowSeconds,
            'pruned_records_total' => $this->prunedRecordsTotal,
            'last_pruned_records' => $this->lastPrunedRecords,
            'directory_path' => $this->directoryPath,
        ];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withAlertLock(string $nodeId, string $alertName, callable $callback): mixed
    {
        $nodeDirectory = $this->nodeDirectory($nodeId);
        if (!is_dir($nodeDirectory)) {
            mkdir($nodeDirectory, 0777, true);
        }

        $lockPath = $this->lockPathForAlert($nodeId, $alertName);
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open database telemetry alert sampling lock file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire database telemetry alert sampling lock.');
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function filePathForAlert(string $nodeId, string $alertName): string
    {
        return $this->nodeDirectory($nodeId) . DIRECTORY_SEPARATOR . $this->alertHash($alertName) . '.json';
    }

    private function lockPathForAlert(string $nodeId, string $alertName): string
    {
        return $this->nodeDirectory($nodeId) . DIRECTORY_SEPARATOR . $this->alertHash($alertName) . '.lock';
    }

    private function cleanupLockPathForNode(string $nodeId): string
    {
        return $this->nodeDirectory($nodeId) . DIRECTORY_SEPARATOR . '__cleanup__.lock';
    }

    private function alertHash(string $alertName): string
    {
        return hash('sha256', trim($alertName));
    }

    private function nodeDirectory(string $nodeId): string
    {
        return $this->directoryPath . DIRECTORY_SEPARATOR . $this->sanitizePathSegment($nodeId);
    }

    private function normalizeNodeId(string $nodeId): string
    {
        $normalized = trim($nodeId);

        return $normalized !== '' ? $normalized : 'unknown-node';
    }

    private function sanitizePathSegment(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $value);

        return is_string($safe) && trim($safe) !== '' ? $safe : 'unknown-node';
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(string $filePath): array
    {
        $contents = file_get_contents($filePath);
        if (!is_string($contents) || trim($contents) === '') {
            return [];
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to decode database telemetry alert sampling record.', 0, $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writePayload(string $filePath, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database telemetry alert sampling record.', 0, $exception);
        }

        file_put_contents($filePath, $json, LOCK_EX);
    }

    private function deleteDirectoryContents(string $directoryPath): void
    {
        $items = scandir($directoryPath);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $path = $directoryPath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryContents($path);
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }
    }

    private function pruneExpiredAlertsForNode(string $nodeId, string $activeAlertName): void
    {
        $this->lastPrunedRecords = 0;
        $nodeDirectory = $this->nodeDirectory($nodeId);
        if (!is_dir($nodeDirectory)) {
            return;
        }

        $cleanupHandle = fopen($this->cleanupLockPathForNode($nodeId), 'c+');
        if ($cleanupHandle === false) {
            return;
        }

        try {
            if (!flock($cleanupHandle, LOCK_EX | LOCK_NB)) {
                return;
            }

            try {
                $activeAlertHash = $this->alertHash($activeAlertName);
                $files = glob($nodeDirectory . DIRECTORY_SEPARATOR . '*.json');
                if (!is_array($files)) {
                    return;
                }

                foreach ($files as $filePath) {
                    if (!is_string($filePath) || !is_file($filePath)) {
                        continue;
                    }

                    $alertHash = pathinfo($filePath, PATHINFO_FILENAME);
                    if (!is_string($alertHash) || $alertHash === '' || $alertHash === $activeAlertHash) {
                        continue;
                    }

                    $lockPath = $nodeDirectory . DIRECTORY_SEPARATOR . $alertHash . '.lock';
                    $lockHandle = fopen($lockPath, 'c+');
                    if ($lockHandle === false) {
                        continue;
                    }

                    try {
                        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                            continue;
                        }

                        $payload = $this->readPayload($filePath);
                        if ($payload !== [] && !$this->isExpired($payload, $this->now())) {
                            continue;
                        }

                        @unlink($filePath);
                        $this->lastPrunedRecords++;
                        $this->prunedRecordsTotal++;
                    } finally {
                        flock($lockHandle, LOCK_UN);
                        fclose($lockHandle);
                    }

                    if (!is_file($filePath) && is_file($lockPath)) {
                        @unlink($lockPath);
                    }
                }
            } finally {
                flock($cleanupHandle, LOCK_UN);
            }
        } finally {
            fclose($cleanupHandle);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isExpired(array $payload, \DateTimeImmutable $now): bool
    {
        if ($this->windowSeconds === null || $this->windowSeconds <= 0) {
            return false;
        }

        $updatedAt = $this->updatedAt($payload);
        if (!$updatedAt instanceof \DateTimeImmutable) {
            return true;
        }

        return ($now->getTimestamp() - $updatedAt->getTimestamp()) >= $this->windowSeconds;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updatedAt(array $payload): ?\DateTimeImmutable
    {
        $updatedAt = trim((string) ($payload['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($updatedAt);
        } catch (\Exception) {
            return null;
        }
    }

    private function now(): \DateTimeImmutable
    {
        $clock = $this->clock;

        return $clock instanceof \Closure
            ? $clock()
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
