<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\DatabaseHealthAggregation;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class JsonLineDatabaseHealthStore implements DatabaseHealthStoreInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function persist(DatabaseTelemetryReport $report): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        try {
            $line = json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database health ledger line.', 0, $exception);
        }

        file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function latest(): ?DatabaseTelemetryReport
    {
        $recent = $this->recent(1);
        $last = $recent[array_key_last($recent)] ?? null;

        return $last instanceof DatabaseTelemetryReport ? $last : null;
    }

    public function recent(int $limit = 10): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $slice = array_slice($lines, -max(1, $limit));
        $reports = [];

        foreach ($slice as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            try {
                $payload = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new \RuntimeException('Unable to decode database health ledger line.', 0, $exception);
            }

            if (!is_array($payload)) {
                continue;
            }

            $reports[] = DatabaseTelemetryReport::fromArray($payload);
        }

        return $reports;
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
