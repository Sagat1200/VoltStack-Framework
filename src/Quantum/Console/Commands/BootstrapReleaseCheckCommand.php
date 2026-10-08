<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Bootstrap\Release\BootstrapReleaseChecker;
use Quantum\Bootstrap\Telemetry\BootstrapBudgetTelemetryEmitter;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;

final class BootstrapReleaseCheckCommand extends Command
{
    public function name(): string
    {
        return 'bootstrap:release-check';
    }

    public function description(): string
    {
        return 'Ejecuta un release-check reproducible del bootstrap con budgets por fase y total.';
    }

    public function usage(): string
    {
        return 'bootstrap:release-check [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--budget-total-ms=250] [--phase-budgets=DISCOVERING:25,BOOTING:50] [--require-published-config] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--profile=' => 'Profile usado para construir el plan del release-check.',
            '--artifact-dir=' => 'Directorio de artifacts a usar/publicar durante el release-check.',
            '--budget-total-ms=' => 'Budget total maximo permitido para el bootstrap.',
            '--phase-budgets=' => 'Lista separada por comas PHASE:MS para budgets por fase.',
            '--require-published-config' => 'Exige una generation activa y sin drift antes de ejecutar el release-check.',
            '--emit-telemetry' => 'Emite telemetry de fases y reporte budget del release-check.',
            '--json' => 'Emite un payload JSON estable con el reporte del release-check.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $profile = is_string($input->option('profile')) ? $input->option('profile') : 'release';
        $artifactDirectory = is_string($input->option('artifact-dir')) ? $input->option('artifact-dir') : null;
        $budget = BootstrapBudget::fromValues(
            totalMaximumMs: $this->floatOption($input, 'budget-total-ms'),
            phaseMaximumsMs: $this->parsePhaseBudgets($input),
        );

        $report = (new BootstrapReleaseChecker($this->basePath))->run(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            budget: $budget,
            emitPhaseTelemetry: $input->hasOption('emit-telemetry'),
            requirePublishedConfig: $input->hasOption('require-published-config'),
        );

        if ($input->hasOption('emit-telemetry')) {
            (new BootstrapBudgetTelemetryEmitter(
                $report->result()->app()->make(TelemetryManagerInterface::class),
            ))->emit($report);
        }

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('Bootstrap release check:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf('  State: %s', $report->result()->state()->value));
            $output->writeln(sprintf('  Ready: %s', $report->result()->ready() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Warmed: %s', $report->result()->warmed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Generation id: %s', $report->result()->generationId() ?? '-'));
            $output->writeln(sprintf('  Manifest path: %s', $report->result()->manifestPath() ?? '-'));
            $output->writeln(sprintf('  Total duration: %.3f ms', $report->budget()->totalDurationMs()));
            $output->writeln(sprintf(
                '  Total budget: %s',
                $report->budget()->totalMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->budget()->totalMaximumMs())
                    : '-'
            ));

            if ($report->budget()->phaseDurationsMs() !== []) {
                $output->writeln('  Phase durations:');

                foreach ($report->budget()->phaseDurationsMs() as $phase => $duration) {
                    $limit = $report->budget()->phaseMaximumsMs()[$phase] ?? null;
                    $output->writeln(sprintf(
                        '    - %s: %.3f ms%s',
                        $phase,
                        $duration,
                        $limit !== null ? sprintf(' (budget %.3f ms)', $limit) : '',
                    ));
                }
            }

            if ($report->budget()->violations() !== []) {
                $output->writeln('  Violations:');

                foreach ($report->budget()->violations() as $violation) {
                    $output->writeln(sprintf('    - %s', $violation));
                }
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        return $report->passed() ? 0 : 1;
    }

    private function floatOption(Input $input, string $name): ?float
    {
        $value = $input->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $numeric = (float) $value;

        return $numeric > 0 ? $numeric : null;
    }

    /**
     * @return array<string, float>
     */
    private function parsePhaseBudgets(Input $input): array
    {
        $raw = $input->option('phase-budgets');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $budgets = [];

        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, ':')) {
                continue;
            }

            [$phase, $limit] = explode(':', $segment, 2);
            $phase = strtoupper(trim($phase));
            $numeric = (float) trim($limit);

            if ($phase === '' || $numeric <= 0) {
                continue;
            }

            $budgets[$phase] = $numeric;
        }

        return $budgets;
    }
}
