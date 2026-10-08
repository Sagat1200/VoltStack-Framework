<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use Quantum\Config\ConfigRepository;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface as QuantumExceptionHandlerInterface;
use Quantum\Exceptions\Contracts\ExceptionManagerInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use VoltStack\Framework\Application;

final class ExceptionDoctorInspector
{
    private const RECOMMENDED_BUFFER_RECORDS = 256;
    private const RECOMMENDED_BUFFER_BYTES = 2097152;

    public function inspect(Application $app): ExceptionDoctorReport
    {
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $rawConfig = $app->config('exceptions', []);
        $exceptionConfig = is_array($rawConfig) ? $rawConfig : [];

        if ($config->has('exceptions')) {
            $fromRepository = $config->get('exceptions', []);
            $exceptionConfig = is_array($fromRepository) ? $fromRepository : [];
        }

        $effects = ['exceptions_config_loaded'];
        $warnings = [];
        $issues = [];
        $diagnostics = [];
        $compiledPlan = null;

        try {
            $compiledPlan = $app->make(ExceptionPlanCompiler::class)->compile($exceptionConfig);
            $effects[] = 'exception_plan_compiled';
            $diagnostics['effective_plan'] = [
                'environment' => $compiledPlan->environment(),
                'runtime' => $compiledPlan->runtime(),
                'debug' => $compiledPlan->debug(),
                'fingerprint' => $compiledPlan->fingerprint(),
            ];
        } catch (ExceptionCompilationException $exception) {
            $issues[] = 'config_invalid';
            $diagnostics['compilation_error'] = $exception->getMessage();
        }

        $environment = trim((string) ($exceptionConfig['environment'] ?? ($compiledPlan?->environment() ?? 'production')));
        $debug = ($exceptionConfig['debug'] ?? ($compiledPlan?->debug() ?? false)) === true;
        $bufferRecords = $exceptionConfig['reporting']['buffer_records'] ?? null;
        $bufferBytes = $exceptionConfig['reporting']['buffer_bytes'] ?? null;

        if ($environment === 'production' && $debug) {
            $issues[] = 'debug_enabled_in_production';
        }

        if (is_int($bufferRecords) && $bufferRecords > self::RECOMMENDED_BUFFER_RECORDS) {
            $warnings[] = 'buffer_records_above_recommended_budget';
        }

        if (is_int($bufferBytes) && $bufferBytes > self::RECOMMENDED_BUFFER_BYTES) {
            $warnings[] = 'buffer_bytes_above_recommended_budget';
        }

        try {
            $app->make(TransportMapperInterface::class);
            $app->make(ExceptionManagerInterface::class);
            $app->make(QuantumExceptionHandlerInterface::class);
            $effects[] = 'exception_bridge_resolved';
        } catch (\Throwable $exception) {
            $issues[] = 'exception_bridge_unavailable';
            $diagnostics['bridge_error'] = $exception->getMessage();
        }

        if ($compiledPlan !== null) {
            $status = (new ExceptionPlanStatusInspector())->inspect($app);
            $diagnostics['published_plan_status'] = $status->toArray();

            if (! $status->publishedArtifactExists()) {
                $warnings[] = 'published_plan_missing';
            } elseif ($status->publishedArtifactError() !== null) {
                $issues[] = 'published_plan_invalid';
            } else {
                if (! $status->publishedCompatible()) {
                    $issues[] = 'published_plan_incompatible';
                }

                if (! $status->publishedMatchesEffective()) {
                    $warnings[] = 'published_plan_obsolete';
                }
            }
        } else {
            $diagnostics['published_plan_status'] = [
                'skipped' => true,
                'reason' => 'effective_plan_unavailable',
            ];
        }

        $warnings = array_values(array_unique($warnings));
        $issues = array_values(array_unique($issues));

        $outcome = $issues !== []
            ? 'fail'
            : ($warnings !== [] ? 'warn' : 'ok');

        return new ExceptionDoctorReport(
            schemaVersion: 'exceptions.doctor.v1',
            operationId: bin2hex(random_bytes(8)),
            outcome: $outcome,
            effects: $effects,
            warnings: $warnings,
            issues: $issues,
            configuration: [
                'environment' => $environment,
                'debug' => $debug,
                'buffer_records' => is_int($bufferRecords) ? $bufferRecords : null,
                'buffer_bytes' => is_int($bufferBytes) ? $bufferBytes : null,
            ],
            diagnostics: $diagnostics,
        );
    }
}
