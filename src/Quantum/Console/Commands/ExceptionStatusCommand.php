<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Diagnostics\ExceptionPlanStatusInspector;
use VoltStack\Framework\Application;

final class ExceptionStatusCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:status';
    }

    public function description(): string
    {
        return 'Inspecciona el plan de excepciones efectivo, el artefacto publicado y el posible drift.';
    }

    public function usage(): string
    {
        return 'exceptions:status [--strict] [--json] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--strict' => 'Devuelve exit code 1 si el reporte contiene alertas.',
            '--json' => 'Emite un payload JSON estable con el reporte de status.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de inspeccionar.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(
            function (Application $app) use ($input, $output): int {
            $report = (new ExceptionPlanStatusInspector())->inspect($app);

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report->toArray(),
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $effective = $report->effectivePlan();
                $published = $report->publishedPlan();

                $output->writeln('Exceptions status:');
                $output->writeln(sprintf('  Healthy: %s', $report->healthy() ? 'yes' : 'no'));
                $output->writeln(sprintf('  Artifact path: %s', $report->artifactPath()));
                $output->writeln(sprintf(
                    '  Published artifact exists: %s',
                    $report->publishedArtifactExists() ? 'yes' : 'no',
                ));
                $output->writeln(sprintf(
                    '  Published matches effective: %s',
                    $report->publishedMatchesEffective() ? 'yes' : 'no',
                ));
                $output->writeln(sprintf(
                    '  Published compatible: %s',
                    $report->publishedCompatible() ? 'yes' : 'no',
                ));
                $output->writeln(sprintf('  Effective environment: %s', $effective->environment()));
                $output->writeln(sprintf('  Effective runtime: %s', $effective->runtime()));
                $output->writeln(sprintf('  Effective fingerprint: %s', $effective->fingerprint()));
                $output->writeln(sprintf('  Effective policy revision: %s', $effective->policyRevision()));
                $output->writeln(sprintf('  Effective PHP runtime version: %s', $effective->phpRuntimeVersion()));
                $output->writeln(sprintf(
                    '  Published fingerprint: %s',
                    $published?->fingerprint() ?? '-',
                ));

                if ($report->publishedArtifactError() !== null) {
                    $output->writeln(sprintf('  Published artifact error: %s', $report->publishedArtifactError()));
                }

                if ($report->alerts() !== []) {
                    $output->writeln('  Alerts:');

                    foreach ($report->alerts() as $alert) {
                        $output->writeln(sprintf('    - %s', $alert));
                    }
                }
            }

            if ($input->hasOption('strict') && ! $report->healthy()) {
                return 1;
            }

            return 0;
        },
            requirePublishedConfig: $input->hasOption('require-published-config'),
        );
    }
}
