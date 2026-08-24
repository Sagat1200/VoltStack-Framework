<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use DateTimeInterface;
use JsonException;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class JsonLineDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
    ) {
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $line = $this->encodeLine($report);

        if (strlen($line) > $this->maxBytesPerLine) {
            $line = $this->encodeLine($report, true);
        }

        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function filePath(): string
    {
        return $this->filePath;
    }

    private function encodeLine(DatabaseTelemetryReport $report, bool $truncatePayload = false): string
    {
        $payload = $truncatePayload
            ? ['_truncated' => true]
            : $this->sanitize($report->toArray());

        try {
            return json_encode([
                'type' => 'database_telemetry',
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database telemetry JSON line.', 0, $exception);
        }
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return '[depth-exceeded]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) <= 2048) {
                return $value;
            }

            return substr($value, 0, 2048) . '...';
        }

        if (is_array($value)) {
            $items = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $count++;
                if ($count > 200) {
                    $items['_truncated'] = true;
                    break;
                }

                $items[is_int($key) ? $key : (string) $key] = $this->sanitize($item, $depth + 1);
            }

            return $items;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return ['_type' => $value::class];
        }

        return '[unserializable]';
    }
}
