<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Engine;

use DateTimeInterface;
use JsonException;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;

final class JsonLineControllerEventDispatcher implements ControllerEventDispatcherInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
    ) {
    }

    public function dispatch(ControllerEventInterface $event): void
    {
        $line = $this->encodeLine($event);

        if (strlen($line) > $this->maxBytesPerLine) {
            $line = $this->encodeLine($event, true);
        }

        $directory = dirname($this->filePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->filePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function filePath(): string
    {
        return $this->filePath;
    }

    private function encodeLine(ControllerEventInterface $event, bool $truncatePayload = false): string
    {
        $payload = $truncatePayload
            ? ['_truncated' => true]
            : $this->sanitize($event->payload());

        try {
            return json_encode([
                'type' => 'controller_event',
                'name' => $event->name(),
                'version' => $event->version(),
                'executionId' => $event->executionId(),
                'sequence' => $event->sequence(),
                'occurredAt' => $event->occurredAt()->format(DATE_ATOM),
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode controller event JSON line.', 0, $exception);
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

            return substr($value, 0, 2048) . '…';
        }

        if (is_array($value)) {
            $items = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $count++;

                if ($count > 100) {
                    $items['_truncated'] = true;
                    break;
                }

                $normalizedKey = is_int($key) ? $key : (string) $key;
                $items[$normalizedKey] = $this->sanitize($item, $depth + 1);
            }

            return $items;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_object($value)) {
            return [
                '_type' => $value::class,
            ];
        }

        return '[unserializable]';
    }
}

