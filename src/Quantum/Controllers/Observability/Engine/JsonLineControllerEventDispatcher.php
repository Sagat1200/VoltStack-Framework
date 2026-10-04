<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Engine;

use JsonException;
use Quantum\Config\Diagnostics\ConfigRedactor;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;

final class JsonLineControllerEventDispatcher implements ControllerEventDispatcherInterface
{
    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
        private readonly ?ConfigRedactor $redactor = null,
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
        return ($this->redactor ?? new ConfigRedactor())->redact($value);
    }
}
