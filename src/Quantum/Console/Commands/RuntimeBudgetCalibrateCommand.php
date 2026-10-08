<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrator;
use VoltStack\Runtime\Telemetry\RuntimeBudgetCalibrationTelemetryEmitter;

final class RuntimeBudgetCalibrateCommand extends Command
{
    public function name(): string
    {
        return 'runtime:budget-calibrate';
    }

    public function description(): string
    {
        return 'Calibra empíricamente budgets runtime por driver a partir de múltiples smoke-checks medidos.';
    }

    public function usage(): string
    {
        return 'runtime:budget-calibrate [--driver=frankenphp] [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--calibration-dir=storage/framework/runtime-budget] [--requests=/,GET:/health] [--warmup=2] [--iterations=10] [--multiplier=1.25] [--require-published-config] [--publish] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime a calibrar.',
            '--profile=' => 'Profile operativo asociado a la calibracion.',
            '--artifact-dir=' => 'Directorio bootstrap a inspeccionar durante la calibracion.',
            '--calibration-dir=' => 'Directorio base donde se publican calibraciones runtime por driver.',
            '--requests=' => 'Lista separada por comas con requests tipo / o METHOD:/path.',
            '--warmup=' => 'Cantidad de corridas warmup previas a la medicion.',
            '--iterations=' => 'Cantidad de corridas medidas para calcular el budget recomendado.',
            '--multiplier=' => 'Factor de seguridad aplicado sobre el maximo observado.',
            '--require-published-config' => 'Exige una generation activa y sin drift antes de calibrar.',
            '--publish' => 'Publica y activa la calibracion recomendada como artefacto runtime del driver.',
            '--emit-telemetry' => 'Emite telemetry con el reporte de calibracion.',
            '--json' => 'Emite un payload JSON estable con el reporte de calibracion.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $driver = is_string($input->option('driver')) ? $input->option('driver') : null;
        $profile = is_string($input->option('profile')) ? $input->option('profile') : 'release';
        $artifactDirectory = is_string($input->option('artifact-dir')) ? $input->option('artifact-dir') : null;
        $calibrationDirectory = is_string($input->option('calibration-dir')) ? $input->option('calibration-dir') : null;

        $report = (new RuntimeBudgetCalibrator($this->basePath))->run(
            driver: $driver,
            profile: $profile,
            requestDefinitions: $this->parseRequests($input),
            warmupIterations: $this->intOption($input, 'warmup', 2, 0),
            measuredIterations: $this->intOption($input, 'iterations', 10, 1),
            safetyMultiplier: $this->floatOption($input, 'multiplier', 1.25, 1.0),
            artifactDirectory: $artifactDirectory,
            requirePublishedConfig: $input->hasOption('require-published-config'),
        );
        $publishedArtifact = null;

        if ($input->hasOption('publish')) {
            $publishedArtifact = $this->runInCommandRuntime(function ($app) use ($report, $calibrationDirectory) {
                $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver(
                    $app,
                    $report->driver(),
                    $calibrationDirectory,
                );
                $artifact = $store->publish($report);
                $store->activateGeneration($artifact->generationId());

                return $store->currentArtifact();
            });
        }

        if ($input->hasOption('emit-telemetry')) {
            $this->runInCommandRuntime(function ($app) use ($report): void {
                (new RuntimeBudgetCalibrationTelemetryEmitter(
                    $app->make(TelemetryManagerInterface::class),
                ))->emit($report);
            });
        }

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'published' => $publishedArtifact !== null,
                'published_artifact' => $publishedArtifact?->toArray(),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('Runtime budget calibration:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Driver: %s', $report->driver()));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf('  Warmup iterations: %d', $report->warmupIterations()));
            $output->writeln(sprintf('  Measured iterations: %d', $report->measuredIterations()));
            $output->writeln(sprintf('  Successful iterations: %d', $report->successfulIterations()));
            $output->writeln(sprintf('  Failed iterations: %d', $report->failedIterations()));
            $output->writeln(sprintf('  Safety multiplier: %.3f', $report->safetyMultiplier()));
            $output->writeln(sprintf(
                '  Current baseline: total=%s request=%s (%s)',
                $report->currentBaseline()->totalMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->currentBaseline()->totalMaximumMs())
                    : '-',
                $report->currentBaseline()->requestMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->currentBaseline()->requestMaximumMs())
                    : '-',
                $report->currentBaseline()->source(),
            ));
            $output->writeln(sprintf(
                '  Recommended budget: total=%s request=%s (%s)',
                $report->recommendedBudget()->totalMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->recommendedBudget()->totalMaximumMs())
                    : '-',
                $report->recommendedBudget()->requestMaximumMs() !== null
                    ? sprintf('%.3f ms', $report->recommendedBudget()->requestMaximumMs())
                    : '-',
                $report->recommendedBudget()->source(),
            ));

            if ($report->totalStats() !== null) {
                $output->writeln(sprintf(
                    '  Total stats: min=%.3f avg=%.3f p95=%.3f max=%.3f',
                    $report->totalStats()['min'],
                    $report->totalStats()['avg'],
                    $report->totalStats()['p95'],
                    $report->totalStats()['max'],
                ));
            }

            if ($report->requestStats() !== null) {
                $output->writeln(sprintf(
                    '  Request stats: min=%.3f avg=%.3f p95=%.3f max=%.3f',
                    $report->requestStats()['min'],
                    $report->requestStats()['avg'],
                    $report->requestStats()['p95'],
                    $report->requestStats()['max'],
                ));
            }

            if ($report->failures() !== []) {
                $output->writeln('  Failures:');

                foreach ($report->failures() as $failure) {
                    $output->writeln(sprintf(
                        '    - Iteration %d: %s',
                        $failure['iteration'],
                        implode(' | ', $failure['violations']),
                    ));
                }
            }

            if ($publishedArtifact !== null) {
                $output->writeln(sprintf(
                    '  Published calibration generation: %s',
                    $publishedArtifact->generationId(),
                ));
                $output->writeln(sprintf(
                    '  Published calibration path: %s',
                    $publishedArtifact->manifestPath(),
                ));
            }

            $output->writeln('  Suggested config:');
            $output->writeln(sprintf("    runtime.budgets.drivers.%s.total_ms = %.3f", $report->driver(), $report->recommendedBudget()->totalMaximumMs() ?? 0.0));
            $output->writeln(sprintf("    runtime.budgets.drivers.%s.request_ms = %.3f", $report->driver(), $report->recommendedBudget()->requestMaximumMs() ?? 0.0));

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        return $report->passed() ? 0 : 1;
    }

    private function intOption(Input $input, string $name, int $default, int $minimum): int
    {
        $value = $input->option($name);
        $numeric = is_string($value) && trim($value) !== '' ? (int) $value : $default;

        return max($minimum, $numeric);
    }

    private function floatOption(Input $input, string $name, float $default, float $minimum): float
    {
        $value = $input->option($name);
        $numeric = is_string($value) && trim($value) !== '' ? (float) $value : $default;

        return max($minimum, $numeric);
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
