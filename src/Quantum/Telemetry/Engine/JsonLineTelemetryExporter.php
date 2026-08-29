<?php

declare(strict_types=1);

namespace Quantum\Telemetry\Engine;

use JsonException;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Support\SanitizesTelemetryPayload;
use Quantum\Telemetry\TelemetrySignal;

final class JsonLineTelemetryExporter implements TelemetryExporterInterface
{
    use SanitizesTelemetryPayload;

    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
    ) {
    }

    public function export(TelemetrySignal $signal): void
    {
        $line = $this->encodeLine($signal);

        if (strlen($line) > $this->maxBytesPerLine) {
            $line = $this->encodeLine($signal, true);
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

    private function encodeLine(TelemetrySignal $signal, bool $truncatePayload = false): string
    {
        $payload = $truncatePayload
            ? ['_truncated' => true]
            : $this->sanitizeTelemetryValue($signal->payload);

        try {
            return json_encode([
                'type' => $signal->name,
                'signal_type' => $signal->type,
                'source' => $signal->source,
                'occurred_at' => $signal->occurredAt,
                'request_id' => $signal->requestId,
                'tenant_id' => $signal->tenantId,
                'trace_id' => $signal->traceId,
                'node_id' => $signal->nodeId,
                'attributes' => $truncatePayload ? ['_truncated' => true] : $this->sanitizeTelemetryValue($signal->attributes),
                'alerts' => $truncatePayload ? ['_truncated' => true] : $this->sanitizeTelemetryValue($signal->alerts),
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode telemetry JSON line.', 0, $exception);
        }
    }
}
