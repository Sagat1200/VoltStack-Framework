<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\OpenTelemetryHttpLogExporter;

final class OpenTelemetryDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly OpenTelemetryHttpLogExporter $exporter;
    private readonly DatabaseTelemetryDispatchPreparation $preparation;

    /**
     * @param array<string, string> $headers
     * @param null|\Closure(string, array<string, mixed>, array<string, string>, int): array{status:int, headers:array<string, string>, body:string} $sender
     */
    public function __construct(
        private readonly string $endpoint,
        string $serviceName = 'voltstack-database',
        string $serviceNamespace = 'voltstack.database',
        string $scopeName = 'voltstack.database',
        string $scopeVersion = '1.0.0',
        array $headers = [],
        int $requestTimeoutMs = 2000,
        ?\Closure $sender = null,
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new OpenTelemetryHttpLogExporter(
            endpoint: $endpoint,
            serviceName: $serviceName,
            serviceNamespace: $serviceNamespace,
            scopeName: $scopeName,
            scopeVersion: $scopeVersion,
            headers: $headers,
            requestTimeoutMs: $requestTimeoutMs,
            sender: $sender,
        );
        $this->preparation = new DatabaseTelemetryDispatchPreparation(
            $mapper ?? new DatabaseTelemetrySignalMapper(),
            $alertSampler ?? new DatabaseTelemetrySignalAlertSampler(),
        );
    }

    public function dispatch(DatabaseTelemetryReport $report): DatabaseTelemetryReport
    {
        try {
            $prepared = $this->preparation->prepare($report);
            $this->exporter->export($prepared['signal']);

            return $prepared['report'];
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(str_replace('OpenTelemetry exporter', 'Database OpenTelemetry dispatcher', $exception->getMessage()), 0, $exception);
        }
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }
}
