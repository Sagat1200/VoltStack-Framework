<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\HttpTelemetryExporter;

final class HttpDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly HttpTelemetryExporter $exporter;
    private readonly DatabaseTelemetrySignalMapper $mapper;
    private readonly DatabaseTelemetrySignalAlertSampler $alertSampler;

    /**
     * @param array<string, string> $headers
     * @param null|\Closure(string, array<string, mixed>, array<string, string>, int): array{status:int, headers:array<string, string>, body:string} $sender
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly int $requestTimeoutMs = 2000,
        private readonly ?\Closure $sender = null,
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new HttpTelemetryExporter($endpoint, $headers, $requestTimeoutMs, $sender);
        $this->mapper = $mapper ?? new DatabaseTelemetrySignalMapper();
        $this->alertSampler = $alertSampler ?? new DatabaseTelemetrySignalAlertSampler();
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        try {
            $this->exporter->export($this->alertSampler->apply($this->mapper->map($report)));
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(str_replace('Telemetry exporter', 'Database telemetry webhook', $exception->getMessage()), 0, $exception);
        }
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }
}
