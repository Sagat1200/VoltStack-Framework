<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Release\ExceptionPlanReleaseChecker;

final class ExceptionReleaseCheckCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:release-check';
    }

    public function description(): string
    {
        return 'Ejecuta un release-check reproducible sobre el plan de excepciones publicado.';
    }

    public function usage(): string
    {
        return 'exceptions:release-check [--allow-missing-plan] [--allow-drift] [--allow-incompatible-plan] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--allow-missing-plan' => 'No falla si no existe un plan de excepciones publicado.',
            '--allow-drift' => 'No falla si el plan efectivo difiere del plan publicado.',
            '--allow-incompatible-plan' => 'No falla si el plan publicado no es compatible con el runtime/PHP actual.',
            '--json' => 'Emite un payload JSON estable con el reporte del release-check.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $report = (new ExceptionPlanReleaseChecker($this->basePath))->run(
            requirePublishedPlan: ! $input->hasOption('allow-missing-plan'),
            requirePublishedMatch: ! $input->hasOption('allow-drift'),
            requireCompatiblePlan: ! $input->hasOption('allow-incompatible-plan'),
            commandName: $this->name(),
        );

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $status = $report->status();
            $effective = $status->effectivePlan();
            $published = $status->publishedPlan();

            $output->writeln('Exceptions release check:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf(
                '  Require published plan: %s',
                $report->requirePublishedPlan() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Require published match: %s',
                $report->requirePublishedMatch() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Require compatible plan: %s',
                $report->requireCompatiblePlan() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf('  Effective environment: %s', $effective->environment()));
            $output->writeln(sprintf('  Effective runtime: %s', $effective->runtime()));
            $output->writeln(sprintf('  Effective fingerprint: %s', $effective->fingerprint()));
            $output->writeln(sprintf('  Artifact path: %s', $status->artifactPath()));
            $output->writeln(sprintf(
                '  Published fingerprint: %s',
                $published?->fingerprint() ?? '-',
            ));
            $output->writeln(sprintf(
                '  Published matches effective: %s',
                $status->publishedMatchesEffective() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Published compatible: %s',
                $status->publishedCompatible() ? 'yes' : 'no',
            ));

            if ($report->violations() !== []) {
                $output->writeln('  Violations:');

                foreach ($report->violations() as $violation) {
                    $output->writeln(sprintf('    - %s', $violation));
                }
            }
        }

        return $report->passed() ? 0 : 1;
    }
}
