<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Bootstrap\Status\BootstrapStatusInspector;
use Quantum\Bootstrap\Telemetry\BootstrapTelemetryEmitter;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use VoltStack\Framework\Application;

final class BootstrapStatusCommand extends Command
{
    public function name(): string
    {
        return 'bootstrap:status';
    }

    public function description(): string
    {
        return 'Inspecciona el estado actual del bootstrap y su generacion activa.';
    }

    public function usage(): string
    {
        return 'bootstrap:status [--artifact-dir=storage/framework/bootstrap] [--require-published-config] [--emit-telemetry] [--strict] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--artifact-dir=' => 'Directorio raiz de artifacts del bootstrap a inspeccionar.',
            '--require-published-config' => 'Exige una generation activa y sin drift antes de inspeccionar el bootstrap.',
            '--emit-telemetry' => 'Emite una senal de telemetry con el estado del bootstrap.',
            '--strict' => 'Devuelve exit code 1 si el reporte contiene alertas.',
            '--json' => 'Emite un payload JSON estable con el reporte de status.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $artifactDirectory = is_string($input->option('artifact-dir')) ? $input->option('artifact-dir') : null;

        return $this->runInCommandRuntime(function ($app) use ($artifactDirectory, $input, $output): int {
            if ($input->hasOption('require-published-config')) {
                $this->assertPublishedConfiguration($app);
            }

            $report = (new BootstrapStatusInspector($this->basePath))->inspect(
                $app,
                $artifactDirectory,
            );

            if ($input->hasOption('emit-telemetry')) {
                (new BootstrapTelemetryEmitter($app->make(TelemetryManagerInterface::class)))->emitStatus($report);
            }

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                    'report' => $report->toArray(),
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('Bootstrap status:');
                $output->writeln(sprintf('  Environment: %s', $report->environment()));
                $output->writeln(sprintf('  Booted: %s', $report->booted() ? 'yes' : 'no'));
                $output->writeln(sprintf('  Providers: %d', $report->providerCount()));
                $output->writeln(sprintf('  Artifact dir: %s', $report->artifactDirectory()));
                $output->writeln(sprintf('  Active generation: %s', $report->hasActiveGeneration() ? 'yes' : 'no'));
                $output->writeln(sprintf('  Generation id: %s', $report->generationId() ?? '-'));
                $output->writeln(sprintf('  Manifest path: %s', $report->manifestPath() ?? '-'));
                $output->writeln(sprintf('  Fingerprint: %s', $report->fingerprint() ?? '-'));
                $output->writeln(sprintf('  Schema: %s', $report->schemaVersion() !== null ? (string) $report->schemaVersion() : '-'));

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
        });
    }

    private function assertPublishedConfiguration(Application $app): void
    {
        $status = $app->configStatusInspector()->inspect($app);

        if (! $status->hasActiveGeneration()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for bootstrap status inspection, but no active configuration generation exists.',
            );
        }

        if (! $status->publishedMatchesEffective()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for bootstrap status inspection, but the effective snapshot differs from the active generation.',
            );
        }
    }
}
