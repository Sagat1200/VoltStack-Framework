<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\DatabaseHealthAggregation;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class JsonFileDatabaseHealthStore implements DatabaseHealthStoreInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {}

    public function persist(DatabaseTelemetryReport $report): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        try {
            $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database health snapshot.', 0, $exception);
        }

        file_put_contents($this->filePath, $json, LOCK_EX);
    }

    public function latest(): ?DatabaseTelemetryReport
    {
        if (!is_file($this->filePath)) {
            return null;
        }

        $contents = file_get_contents($this->filePath);
        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to decode database health snapshot.', 0, $exception);
        }

        if (!is_array($payload)) {
            return null;
        }

        return DatabaseTelemetryReport::fromArray($payload);
    }

    public function recent(int $limit = 10): array
    {
        $latest = $this->latest();

        return $latest instanceof DatabaseTelemetryReport ? [$latest] : [];
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseHealthAggregation::aggregate($this->recent($limit));
    }

    public function filePath(): string
    {
        return $this->filePath;
    }
}
