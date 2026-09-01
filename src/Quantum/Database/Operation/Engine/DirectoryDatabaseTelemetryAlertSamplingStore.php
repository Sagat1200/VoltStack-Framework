<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;

final class DirectoryDatabaseTelemetryAlertSamplingStore implements DatabaseTelemetryAlertSamplingStoreInterface
{
    public function __construct(
        private readonly string $directoryPath,
    ) {
    }

    public function nextOccurrence(string $nodeId, string $alertName): int
    {
        $normalizedNodeId = $this->normalizeNodeId($nodeId);
        $normalizedAlertName = trim($alertName);

        return $this->withAlertLock($normalizedNodeId, $normalizedAlertName, function () use ($normalizedNodeId, $normalizedAlertName): int {
            $filePath = $this->filePathForAlert($normalizedNodeId, $normalizedAlertName);
            $occurrence = 1;

            if (is_file($filePath)) {
                $payload = $this->readPayload($filePath);
                $occurrence = max(0, (int) ($payload['occurrence'] ?? 0)) + 1;
            }

            $this->writePayload($filePath, [
                'node_id' => $normalizedNodeId,
                'alert_name' => $normalizedAlertName,
                'occurrence' => $occurrence,
                'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
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
}
