<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Bootstrap\Benchmark\BootstrapBenchmarkRunner;
use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Bootstrap\Telemetry\BootstrapBenchmarkTelemetryEmitter;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;

final class BootstrapBenchmarkCommand extends Command
{
    public function name(): string
    {
        return 'bootstrap:benchmark';
    }

    public function description(): string
    {
        return 'Compara una corrida cold y una warm del bootstrap sobre el mismo directorio de artifacts.';
    }

    public function usage(): string
    {
        return 'bootstrap:benchmark [--profile=release] [--artifact-dir=storage/framework/bootstrap/benchmark] [--budget-total-ms=250] [--phase-budgets=DISCOVERING:25,BOOTING:50] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--profile=' => 'Profile usado para las corridas cold y warm del benchmark.',
            '--artifact-dir=' => 'Directorio de artifacts a reutilizar durante el benchmark.',
            '--budget-total-ms=' => 'Budget total maximo permitido por corrida de bootstrap.',
            '--phase-budgets=' => 'Lista separada por comas PHASE:MS para budgets por fase.',
            '--emit-telemetry' => 'Emite telemetry de fases y del benchmark comparativo.',
            '--json' => 'Emite un payload JSON estable con el reporte del benchmark.',
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

        $report = (new BootstrapBenchmarkRunner($this->basePath))->run(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            budget: $budget,
            emitPhaseTelemetry: $input->hasOption('emit-telemetry'),
        );

        if ($input->hasOption('emit-telemetry')) {
            (new BootstrapBenchmarkTelemetryEmitter(
                $report->warm()->result()->app()->make(TelemetryManagerInterface::class),
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
            $output->writeln('Bootstrap benchmark:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf('  Artifact dir: %s', $report->artifactDirectory()));
            $output->writeln(sprintf('  Cold duration: %.3f ms', $report->coldDurationMs()));
            $output->writeln(sprintf('  Warm duration: %.3f ms', $report->warmDurationMs()));
            $output->writeln(sprintf('  Delta (warm-cold): %.3f ms', $report->deltaMs()));
            $output->writeln(sprintf('  Improvement (cold-warm): %.3f ms', $report->improvementMs()));
            $output->writeln(sprintf('  Warm faster: %s', $report->warmFaster() ? 'yes' : 'no'));

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
