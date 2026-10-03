<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use VoltStack\Runtime\Budget\RuntimeBudget;
use VoltStack\Runtime\Smoke\RuntimeSmokeChecker;
use VoltStack\Runtime\Telemetry\RuntimeSmokeTelemetryEmitter;

final class RuntimeSmokeCheckCommand extends Command
{
    public function name(): string
    {
        return 'runtime:smoke-check';
    }

    public function description(): string
    {
        return 'Ejecuta un smoke-check reproducible del runtime sobre requests reales de la aplicacion.';
    }

    public function usage(): string
    {
        return 'runtime:smoke-check [--driver=frankenphp] [--profile=release] [--requests=/,GET:/health] [--budget-total-ms=50] [--budget-request-ms=25] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime a validar durante el smoke-check.',
            '--profile=' => 'Profile operativo asociado al smoke-check.',
            '--requests=' => 'Lista separada por comas con requests tipo / o METHOD:/path.',
            '--budget-total-ms=' => 'Budget total maximo permitido para el smoke-check runtime.',
            '--budget-request-ms=' => 'Budget maximo permitido por request.',
            '--emit-telemetry' => 'Emite telemetry con el reporte del smoke-check runtime.',
            '--json' => 'Emite un payload JSON estable con el reporte del smoke-check.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $driver = is_string($input->option('driver')) ? $input->option('driver') : null;
        $profile = is_string($input->option('profile')) ? $input->option('profile') : 'release';
        $budget = RuntimeBudget::fromValues(
            totalMaximumMs: $this->floatOption($input, 'budget-total-ms'),
            requestMaximumMs: $this->floatOption($input, 'budget-request-ms'),
        );

        $report = (new RuntimeSmokeChecker($this->basePath))->run(
            driver: $driver,
            profile: $profile,
            requestDefinitions: $this->parseRequests($input),
            budget: $budget,
        );

        if ($input->hasOption('emit-telemetry')) {
            (new RuntimeSmokeTelemetryEmitter(
                $this->bootstrapApplication()->make(TelemetryManagerInterface::class),
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
            $output->writeln('Runtime smoke check:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Driver: %s', $report->driver()));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf('  Requests: %d', count($report->requests())));
            $output->writeln(sprintf('  Total duration: %.3f ms', $report->budget()->totalDurationMs()));
            $output->writeln(sprintf(
                '  Total budget: %s',
                $report->budget()->totalMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->budget()->totalMaximumMs())
                    : '-'
            ));
            $output->writeln(sprintf(
                '  Request budget: %s',
                $report->budget()->requestMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->budget()->requestMaximumMs())
                    : '-'
            ));

            if ($report->requests() !== []) {
                $output->writeln('  Request results:');

                foreach ($report->requests() as $request) {
                    $output->writeln(sprintf(
                        '    - %s %s => %s in %.3f ms (%s)',
                        $request->method(),
                        $request->path(),
                        $request->statusCode() !== null ? (string) $request->statusCode() : 'null',
                        $request->durationMs(),
                        $request->workerDisposition()->value,
                    ));
                }
            }

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
     * @return list<string>
     */
    private function parseRequests(Input $input): array
    {
        $raw = $input->option('requests');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $requests = [];

        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $requests[] = $segment;
        }

        return $requests;
    }
}
