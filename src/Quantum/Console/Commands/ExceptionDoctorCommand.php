<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Contracts\ExceptionManagerInterface;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Diagnostics\ExceptionPlanStatusInspector;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface as QuantumExceptionHandlerInterface;
use VoltStack\Framework\Application;

final class ExceptionDoctorCommand extends Command
{
    private const BASELINE_SYNC_BUDGET_MS = 50;
    private const BASELINE_BUFFER_RECORDS = 256;
    private const BASELINE_BUFFER_BYTES = 2097152;

    public function name(): string
    {
        return 'exceptions:doctor';
    }

    public function description(): string
    {
        return 'Diagnostica el stack de excepciones sin publicar secretos ni mutar artefactos.';
    }

    public function usage(): string
    {
        return 'exceptions:doctor [--strict] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--strict' => 'Devuelve exit code 1 si el diagnostico contiene alertas.',
            '--json' => 'Emite un payload JSON estable con el diagnostico.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function (Application $app) use ($input, $output): int {
            $rawConfig = $app->config('exceptions', []);
            $config = is_array($rawConfig) ? $rawConfig : [];

            $alerts = [];
            $warnings = [];
            $issues = [];
            $effectivePlan = null;

            $this->appendRawConfigurationAlerts($config, $alerts, $issues);

            try {
                $effectivePlan = $app->make(ExceptionPlanCompiler::class)->compile($config);
                $this->appendBudgetAlerts($effectivePlan, $alerts, $warnings);
            } catch (ExceptionCompilationException $exception) {
                $issues[] = 'config_invalid';
                $alerts[] = sprintf(
                    'El plan efectivo de excepciones no pudo compilarse: %s',
                    $exception->getMessage(),
                );
            }

            $status = null;
            $checks = [];

            if ($effectivePlan instanceof ExceptionCompilationPlan) {
                $status = (new ExceptionPlanStatusInspector())->inspect($app);
                $this->appendPublishedPlanAlerts($status, $alerts, $warnings, $issues);
                $checks = $this->bridgeChecks($app);
                $this->appendBridgeAlerts($checks, $alerts, $issues);
            }

            $alerts = array_values(array_unique($alerts));
            $warnings = array_values(array_unique($warnings));
            $issues = array_values(array_unique($issues));
            $outcome = $issues !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'pass');
            $report = [
                'passed' => $outcome === 'pass',
                'outcome' => $outcome,
                'warnings' => $warnings,
                'issues' => $issues,
                'effective' => $effectivePlan instanceof ExceptionCompilationPlan
                    ? $this->describePlan($effectivePlan)
                    : null,
                'published_status' => $status?->toArray(),
                'checks' => $checks,
                'alerts' => $alerts,
            ];

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report,
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('Exceptions doctor:');
                $output->writeln(sprintf('  Outcome: %s', $outcome));
                $output->writeln(sprintf('  Effective plan: %s', $effectivePlan instanceof ExceptionCompilationPlan ? 'available' : 'unavailable'));

                if ($effectivePlan instanceof ExceptionCompilationPlan) {
                    $output->writeln(sprintf('  Environment: %s', $effectivePlan->environment()));
                    $output->writeln(sprintf('  Runtime: %s', $effectivePlan->runtime()));
                    $output->writeln(sprintf('  Fingerprint: %s', $effectivePlan->fingerprint()));
                }

                if ($checks !== []) {
                    $output->writeln('  Bridge checks:');

                    foreach ($checks as $check) {
                        $output->writeln(sprintf(
                            '    - %s: %s',
                            $check['check'],
                            ($check['passed'] ?? false) === true ? 'ok' : 'fail',
                        ));
                    }
                }

                if ($alerts !== []) {
                    $output->writeln('  Alerts:');

                    foreach ($alerts as $alert) {
                        $output->writeln(sprintf('    - %s', $alert));
                    }
                }
            }

            if ($issues !== []) {
                return 1;
            }

            return $input->hasOption('strict') && $warnings !== [] ? 1 : 0;
        });
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $alerts
     * @param list<string> $issues
     */
    private function appendRawConfigurationAlerts(array $config, array &$alerts, array &$issues): void
    {
        $environment = is_string($config['environment'] ?? null) ? trim((string) $config['environment']) : '';
        $debug = $config['debug'] ?? null;

        if ($environment === 'production' && $debug === true) {
            $issues[] = 'debug_enabled_in_production';
            $alerts[] = 'La configuracion de excepciones tiene debug=true en production.';
        }
    }

    /**
     * @param list<string> $alerts
     * @param list<string> $warnings
     */
    private function appendBudgetAlerts(ExceptionCompilationPlan $plan, array &$alerts, array &$warnings): void
    {
        $reporting = $plan->config()['reporting'] ?? [];
        $syncBudgetMs = (int) ($reporting['sync_budget_ms'] ?? self::BASELINE_SYNC_BUDGET_MS);
        $bufferRecords = (int) ($reporting['buffer_records'] ?? self::BASELINE_BUFFER_RECORDS);
        $bufferBytes = (int) ($reporting['buffer_bytes'] ?? self::BASELINE_BUFFER_BYTES);

        if ($syncBudgetMs > self::BASELINE_SYNC_BUDGET_MS) {
            $warnings[] = 'sync_budget_over_baseline';
            $alerts[] = sprintf(
                'El presupuesto sync_budget_ms [%d] excede el presupuesto basal [%d].',
                $syncBudgetMs,
                self::BASELINE_SYNC_BUDGET_MS,
            );
        }

        if ($bufferRecords > self::BASELINE_BUFFER_RECORDS) {
            $warnings[] = 'buffer_records_over_budget';
            $alerts[] = sprintf(
                'El buffer_records [%d] excede el presupuesto basal [%d].',
                $bufferRecords,
                self::BASELINE_BUFFER_RECORDS,
            );
        }

        if ($bufferBytes > self::BASELINE_BUFFER_BYTES) {
            $warnings[] = 'buffer_bytes_over_budget';
            $alerts[] = sprintf(
                'El buffer_bytes [%d] excede el presupuesto basal [%d].',
                $bufferBytes,
                self::BASELINE_BUFFER_BYTES,
            );
        }
    }

    /**
     * @param list<string> $alerts
     * @param list<string> $warnings
     * @param list<string> $issues
     */
    private function appendPublishedPlanAlerts(
        \Quantum\Exceptions\Diagnostics\ExceptionPlanStatusReport $status,
        array &$alerts,
        array &$warnings,
        array &$issues,
    ): void {
        if (! $status->publishedArtifactExists()) {
            $warnings[] = 'published_plan_missing';
            $alerts[] = 'No hay un plan de excepciones publicado.';

            return;
        }

        if ($status->publishedArtifactError() !== null) {
            $issues[] = 'published_plan_invalid';
            $alerts[] = 'El plan de excepciones publicado es invalido, esta corrupto o no pudo cargarse.';

            return;
        }

        if (! $status->publishedCompatible()) {
            $issues[] = 'published_plan_incompatible';
            $alerts[] = 'El plan de excepciones publicado es incompatible con el runtime o la version de PHP actual.';
        }

        if (! $status->publishedMatchesEffective()) {
            $warnings[] = 'published_plan_obsolete';
            $alerts[] = 'El plan de excepciones efectivo difiere del plan publicado.';
        }
    }

    /**
     * @param list<array{check: string, passed: bool, message: string}> $checks
     * @param list<string> $alerts
     * @param list<string> $issues
     */
    private function appendBridgeAlerts(array $checks, array &$alerts, array &$issues): void
    {
        foreach ($checks as $check) {
            if (($check['passed'] ?? false) === true) {
                continue;
            }

            $issues[] = sprintf('bridge_%s_unavailable', (string) ($check['check'] ?? 'unknown'));
            $alerts[] = (string) ($check['message'] ?? 'Bridge diagnostic failed.');
        }
    }

    /**
     * @return list<array{check: string, passed: bool, message: string}>
     */
    private function bridgeChecks(Application $app): array
    {
        $checks = [];

        foreach ([
            'handler' => QuantumExceptionHandlerInterface::class,
            'manager' => ExceptionManagerInterface::class,
            'transport_mapper' => TransportMapperInterface::class,
            'renderer' => ExceptionRendererInterface::class,
            'reporter_pipeline' => ExceptionReporterPipeline::class,
        ] as $check => $serviceId) {
            try {
                $app->make($serviceId);
                $checks[] = [
                    'check' => $check,
                    'passed' => true,
                    'message' => sprintf('El bridge [%s] se resolvio correctamente.', $serviceId),
                ];
            } catch (\Throwable $exception) {
                $checks[] = [
                    'check' => $check,
                    'passed' => false,
                    'message' => sprintf(
                        'No se pudo resolver el bridge [%s]: %s',
                        $serviceId,
                        $exception->getMessage(),
                    ),
                ];
            }
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private function describePlan(ExceptionCompilationPlan $plan): array
    {
        return [
            'environment' => $plan->environment(),
            'runtime' => $plan->runtime(),
            'debug' => $plan->debug(),
            'fingerprint' => $plan->fingerprint(),
            'policy_revision' => $plan->policyRevision(),
            'php_runtime_version' => $plan->phpRuntimeVersion(),
            'reporters' => $plan->reporterIds(),
            'spa_versions' => $plan->spaVersions(),
        ];
    }
}
