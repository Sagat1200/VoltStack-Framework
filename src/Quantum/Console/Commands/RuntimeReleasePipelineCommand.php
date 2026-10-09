<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use VoltStack\Runtime\Budget\RuntimeBudget;
use VoltStack\Runtime\Pipeline\RuntimeReleasePipelineRunner;
use VoltStack\Runtime\Telemetry\RuntimeReleasePipelineTelemetryEmitter;

final class RuntimeReleasePipelineCommand extends Command
{
    public function name(): string
    {
        return 'runtime:release-pipeline';
    }

    public function description(): string
    {
        return 'Ejecuta el pipeline formal release/smoke/rollback del runtime sobre la generation bootstrap activa.';
    }

    public function usage(): string
    {
        return 'runtime:release-pipeline [--driver=frankenphp] [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--requests=/,GET:/health] [--bootstrap-budget-total-ms=250] [--phase-budgets=DISCOVERING:25,BOOTING:50] [--runtime-budget-total-ms=50] [--runtime-budget-request-ms=25] [--require-published-config] [--publish-calibration] [--calibration-dir=storage/framework/runtime-budget] [--warmup=2] [--iterations=10] [--multiplier=1.25] [--emit-phase-telemetry] [--emit-telemetry] [--no-rollback] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime a validar durante el pipeline.',
            '--profile=' => 'Profile operativo usado para release-check, smoke-check y calibracion.',
            '--artifact-dir=' => 'Directorio bootstrap donde se publica la generation validada.',
            '--requests=' => 'Lista separada por comas con requests tipo / o METHOD:/path para el smoke-check.',
            '--bootstrap-budget-total-ms=' => 'Budget total maximo permitido para el release-check del bootstrap.',
            '--phase-budgets=' => 'Lista separada por comas PHASE:MS para budgets por fase del bootstrap.',
            '--runtime-budget-total-ms=' => 'Budget total maximo permitido para el smoke-check runtime.',
            '--runtime-budget-request-ms=' => 'Budget maximo permitido por request en el smoke-check runtime.',
            '--require-published-config' => 'Exige una generation activa y sin drift de configuracion antes del pipeline.',
            '--publish-calibration' => 'Ejecuta calibracion empirica y publica/activa el budget recomendado si el pipeline pasa.',
            '--calibration-dir=' => 'Directorio base donde se publican calibraciones runtime por driver.',
            '--warmup=' => 'Cantidad de corridas warmup para la calibracion opcional.',
            '--iterations=' => 'Cantidad de corridas medidas para la calibracion opcional.',
            '--multiplier=' => 'Factor de seguridad aplicado a la calibracion opcional.',
            '--emit-phase-telemetry' => 'Activa telemetry de fases del release-check bootstrap.',
            '--emit-telemetry' => 'Emite telemetry con el reporte consolidado del pipeline runtime.',
            '--no-rollback' => 'Desactiva el rollback automatico de bootstrap/calibracion cuando el pipeline falla.',
            '--json' => 'Emite un payload JSON estable con el resultado del pipeline.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $driver = is_string($input->option('driver')) ? $input->option('driver') : null;
        $profile = is_string($input->option('profile')) ? $input->option('profile') : 'release';
        $artifactDirectory = is_string($input->option('artifact-dir')) ? $input->option('artifact-dir') : null;
        $calibrationDirectory = is_string($input->option('calibration-dir')) ? $input->option('calibration-dir') : null;

        $report = (new RuntimeReleasePipelineRunner($this->basePath))->run(
            driver: $driver,
            profile: $profile,
            requestDefinitions: $this->parseRequests($input),
            artifactDirectory: $artifactDirectory,
            bootstrapBudget: BootstrapBudget::fromValues(
                totalMaximumMs: $this->nullableFloatOption($input, 'bootstrap-budget-total-ms'),
                phaseMaximumsMs: $this->parsePhaseBudgets($input),
            ),
            runtimeBudget: RuntimeBudget::fromValues(
                totalMaximumMs: $this->nullableFloatOption($input, 'runtime-budget-total-ms'),
                requestMaximumMs: $this->nullableFloatOption($input, 'runtime-budget-request-ms'),
            ),
            requirePublishedConfig: $input->hasOption('require-published-config'),
            publishCalibration: $input->hasOption('publish-calibration'),
            warmupIterations: $this->intOption($input, 'warmup', 2, 0),
            measuredIterations: $this->intOption($input, 'iterations', 10, 1),
            safetyMultiplier: $this->floatOption($input, 'multiplier', 1.25, 1.0),
            calibrationDirectory: $calibrationDirectory,
            rollbackOnFailure: ! $input->hasOption('no-rollback'),
            emitPhaseTelemetry: $input->hasOption('emit-phase-telemetry'),
        );

        if ($input->hasOption('emit-telemetry')) {
            $this->runInCommandRuntime(function ($app) use ($report): void {
                (new RuntimeReleasePipelineTelemetryEmitter(
                    $app->make(TelemetryManagerInterface::class),
                ))->emit($report);
            });
        }

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'report' => $report->toArray(),
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('Runtime release pipeline:');
            $output->writeln(sprintf('  Passed: %s', $report->passed() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Driver: %s', $report->driver()));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf('  Capability evidence: %s', $report->capabilityEvidenceLevel()));
            $output->writeln(sprintf(
                '  Native integration verified: %s',
                $report->nativeIntegrationVerified() ? 'yes' : 'no'
            ));
            if ($report->activeCapabilityEvidence() !== null) {
                $output->writeln(sprintf(
                    '  Active capability evidence generation: %s',
                    $report->activeCapabilityEvidence()->generationId()
                ));
                $output->writeln(sprintf(
                    '  Active capability evidence platform: %s',
                    $report->activeCapabilityEvidence()->platform()
                ));
            }
            $output->writeln(sprintf('  Failed stage: %s', $report->failedStage() ?? '-'));
            $output->writeln(sprintf(
                '  Release generation: %s',
                $report->releaseCheck()->result()->generationId() ?? '-'
            ));
            $output->writeln(sprintf(
                '  Smoke passed: %s',
                $report->smokeCheck()?->passed() === true ? 'yes' : ($report->smokeCheck() === null ? '-' : 'no')
            ));
            $output->writeln(sprintf(
                '  Calibration published: %s',
                $report->publishedCalibration() !== null ? 'yes' : 'no'
            ));
            $output->writeln(sprintf(
                '  Rollback: %s',
                $report->rollback()->triggered()
                    ? ($report->rollback()->bootstrapRolledBack() || $report->rollback()->calibrationRolledBack() ? 'performed' : 'requested')
                    : 'not-needed'
            ));
            $output->writeln(sprintf(
                '  Drain required: %s',
                $report->drain()->required() ? 'yes' : 'no'
            ));
            $output->writeln(sprintf(
                '  Drain action: %s',
                $report->drain()->action()
            ));

            if ($report->violations() !== []) {
                $output->writeln('  Violations:');

                foreach ($report->violations() as $violation) {
                    $output->writeln(sprintf('    - %s', $violation));
                }
            }

            if ($report->capabilityEvidenceNotes() !== []) {
                $output->writeln('  Capability notes:');

                foreach ($report->capabilityEvidenceNotes() as $note) {
                    $output->writeln(sprintf('    - %s', $note));
                }
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        return $report->passed() ? 0 : 1;
    }

    private function nullableFloatOption(Input $input, string $name): ?float
    {
        $value = $input->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $numeric = (float) $value;

        return $numeric > 0 ? $numeric : null;
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
