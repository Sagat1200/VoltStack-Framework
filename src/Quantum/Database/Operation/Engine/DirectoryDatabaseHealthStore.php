<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\DatabaseHealthAggregation;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class DirectoryDatabaseHealthStore implements DatabaseHealthStoreInterface
{
    public function __construct(
        private readonly string $directoryPath,
    ) {}

    public function persist(DatabaseTelemetryReport $report): void
    {
        if (!is_dir($this->directoryPath)) {
            mkdir($this->directoryPath, 0777, true);
        }

        $nodeId = $report->nodeId !== null && trim($report->nodeId) !== ''
            ? $report->nodeId
            : 'unknown-node';

        $filePath = $this->filePathForNode($nodeId);

        try {
            $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode directory database health snapshot.', 0, $exception);
        }

        file_put_contents($filePath, $json, LOCK_EX);
    }

    public function latest(): ?DatabaseTelemetryReport
    {
        $recent = $this->recent(1);
        $latest = $recent[array_key_last($recent)] ?? null;

        return $latest instanceof DatabaseTelemetryReport ? $latest : null;
    }

    public function recent(int $limit = 10): array
    {
        if (!is_dir($this->directoryPath)) {
            return [];
        }

        $files = glob($this->directoryPath . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $reports = [];

        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            $contents = file_get_contents($file);
            if (!is_string($contents) || trim($contents) === '') {
                continue;
            }

            try {
                $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new \RuntimeException('Unable to decode directory database health snapshot.', 0, $exception);
            }

            if (!is_array($payload)) {
                continue;
            }

            $reports[] = DatabaseTelemetryReport::fromArray($payload);
        }

        usort($reports, static function (DatabaseTelemetryReport $left, DatabaseTelemetryReport $right): int {
            return strcmp($left->generatedAt, $right->generatedAt);
        });

        return array_values(array_slice($reports, -max(1, $limit)));
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseHealthAggregation::aggregate($this->recent($limit));
    }

    public function directoryPath(): string
    {
        return $this->directoryPath;
    }

    private function filePathForNode(string $nodeId): string
    {
        $safeNode = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $nodeId);
        $safeNode = is_string($safeNode) && trim($safeNode) !== '' ? $safeNode : 'unknown-node';

        return $this->directoryPath . DIRECTORY_SEPARATOR . $safeNode . '.json';
    }
}
