<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Config\Release\ConfigReleaseChecker;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ConfigReleaseCheckCommand extends Command
{
    public function name(): string
    {
        return 'config:release-check';
    }

    public function description(): string
    {
        return 'Ejecuta un release-check reproducible sobre la publication activa de configuracion.';
    }

    public function usage(): string
    {
        return 'config:release-check [--allow-missing-generation] [--allow-drift] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--allow-missing-generation' => 'No falla si no existe una generacion de configuracion activa.',
            '--allow-drift' => 'No falla si el snapshot efectivo difiere de la generacion publicada activa.',
            '--emit-telemetry' => 'Emite telemetry con el reporte del release-check de configuracion.',
            '--json' => 'Emite un payload JSON estable con el reporte del release-check.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $report = (new ConfigReleaseChecker($this->basePath))->run(
            requireActiveGeneration: ! $input->hasOption('allow-missing-generation'),
            requirePublishedMatch: ! $input->hasOption('allow-drift'),
            emitTelemetry: $input->hasOption('emit-telemetry'),
            commandName: $this->name(),
        );

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $status = $report->status();

            $output->writeln('Config release check:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf(
                '  Require active generation: %s',
                $report->requireActiveGeneration() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Require published match: %s',
                $report->requirePublishedMatch() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf('  Environment: %s', $status->environment()));
            $output->writeln(sprintf('  Scope: %s', $status->scopeKind()));
            $output->writeln(sprintf('  Effective config id: %s', $status->effectiveConfigId()));
            $output->writeln(sprintf('  Active generation: %s', $status->hasActiveGeneration() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Generation id: %s', $status->generationId() ?? '-'));
            $output->writeln(sprintf(
                '  Published matches effective: %s',
                $status->publishedMatchesEffective() ? 'yes' : 'no',
            ));

            if ($report->violations() !== []) {
                $output->writeln('  Violations:');

                foreach ($report->violations() as $violation) {
                    $output->writeln(sprintf('    - %s', $violation));
                }
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        return $report->passed() ? 0 : 1;
    }
}
