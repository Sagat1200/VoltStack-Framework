<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use VoltStack\Runtime\Status\RuntimeStatusInspector;
use VoltStack\Runtime\Telemetry\RuntimeTelemetryEmitter;

final class RuntimeStatusCommand extends Command
{
    public function name(): string
    {
        return 'runtime:status';
    }

    public function description(): string
    {
        return 'Inspecciona el driver runtime, sus capacidades y el modo de ejecucion configurado.';
    }

    public function usage(): string
    {
        return 'runtime:status [--driver=frankenphp] [--max-requests=1] [--emit-telemetry] [--strict]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime a inspeccionar.',
            '--max-requests=' => 'Cantidad maxima de requests por worker para la inspeccion.',
            '--emit-telemetry' => 'Emite una senal de telemetry con el estado del runtime.',
            '--strict' => 'Devuelve exit code 1 si el reporte contiene alertas.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $maxRequests = max(1, (int) ($input->option('max-requests', '1')));
        $driver = is_string($input->option('driver')) ? $input->option('driver') : null;
        $report = (new RuntimeStatusInspector())->inspect($app, $driver, $maxRequests);

        $output->writeln('Runtime status:');
        $output->writeln(sprintf('  Driver: %s', $report->driver()));
        $output->writeln(sprintf('  Max requests: %d', $report->maxRequests()));
        $output->writeln(sprintf('  Persistent: %s', $report->persistent() ? 'yes' : 'no'));
        $output->writeln(sprintf('  Concurrent: %s', $report->concurrent() ? 'yes' : 'no'));
        $output->writeln(sprintf('  Streaming: %s', $report->streaming() ? 'yes' : 'no'));
        $output->writeln(sprintf('  Drain control: %s', $report->drainControl() ? 'yes' : 'no'));
        $output->writeln(sprintf('  Native HTTP: %s', $report->nativeHttp() ? 'yes' : 'no'));
        $output->writeln(sprintf('  Supported drivers: %s', implode(', ', $report->supportedDrivers())));

        if ($report->alerts() !== []) {
            $output->writeln('  Alerts:');

            foreach ($report->alerts() as $alert) {
                $output->writeln(sprintf('    - %s', $alert));
            }
        }

        if ($input->hasOption('emit-telemetry')) {
            (new RuntimeTelemetryEmitter($app->make(TelemetryManagerInterface::class)))->emitStatus($report);
            $output->writeln('  Telemetry: emitted');
        }

        if ($input->hasOption('strict') && ! $report->healthy()) {
            return 1;
        }

        return 0;
    }
}
