<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Config\Telemetry\ConfigTelemetryEmitter;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;

final class ConfigStatusCommand extends Command
{
    public function name(): string
    {
        return 'config:status';
    }

    public function description(): string
    {
        return 'Inspecciona el estado efectivo de configuracion, la publication activa y el posible drift.';
    }

    public function usage(): string
    {
        return 'config:status [--emit-telemetry] [--strict] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--emit-telemetry' => 'Emite una senal de telemetry con el estado de configuracion.',
            '--strict' => 'Devuelve exit code 1 si el reporte contiene alertas.',
            '--json' => 'Emite un payload JSON estable con el reporte de status.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $report = $app->configStatusInspector()->inspect($app);

        if ($input->hasOption('emit-telemetry')) {
            (new ConfigTelemetryEmitter($app->make(TelemetryManagerInterface::class)))->emitStatus($report);
        }

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('Config status:');
            $output->writeln(sprintf('  Environment: %s', $report->environment()));
            $output->writeln(sprintf('  Scope: %s', $report->scopeKind()));
            $output->writeln(sprintf('  Scope id: %s', $report->scopeId() ?? '-'));
            $output->writeln(sprintf('  Parent scope id: %s', $report->parentScopeId() ?? '-'));
            $output->writeln(sprintf('  Has overrides: %s', $report->hasScopeOverrides() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Effective config id: %s', $report->effectiveConfigId()));
            $output->writeln(sprintf('  Documents: %d', $report->documentCount()));
            $output->writeln(sprintf('  Provenance entries: %d', $report->provenanceCount()));
            $output->writeln(sprintf('  Active generation: %s', $report->hasActiveGeneration() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Generation id: %s', $report->generationId() ?? '-'));
            $output->writeln(sprintf('  Manifest path: %s', $report->manifestPath() ?? '-'));
            $output->writeln(sprintf('  Published config id: %s', $report->publishedConfigId() ?? '-'));
            $output->writeln(sprintf(
                '  Published matches effective: %s',
                $report->publishedMatchesEffective() ? 'yes' : 'no',
            ));

            if ($report->alerts() !== []) {
                $output->writeln('  Alerts:');

                foreach ($report->alerts() as $alert) {
                    $output->writeln(sprintf('    - %s', $alert));
                }
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        if ($input->hasOption('strict') && ! $report->healthy()) {
            return 1;
        }

        return 0;
    }
}
