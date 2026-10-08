<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use VoltStack\Framework\Application;

final class ExceptionValidateCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:validate';
    }

    public function description(): string
    {
        return 'Valida el arbol de configuracion de excepciones y muestra el plan efectivo sin publicarlo.';
    }

    public function usage(): string
    {
        return 'exceptions:validate [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Emite un payload JSON estable con el resultado de validacion.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function (Application $app) use ($input, $output): int {
            $rawConfig = $app->config('exceptions', []);
            $config = is_array($rawConfig) ? $rawConfig : [];

            try {
                $plan = $app->make(ExceptionPlanCompiler::class)->compile($config);
                $report = [
                    'passed' => true,
                    'environment' => $plan->environment(),
                    'runtime' => $plan->runtime(),
                    'debug' => $plan->debug(),
                    'fingerprint' => $plan->fingerprint(),
                    'policy_revision' => $plan->policyRevision(),
                    'php_runtime_version' => $plan->phpRuntimeVersion(),
                    'reporters' => $plan->reporterIds(),
                    'spa_versions' => $plan->spaVersions(),
                    'config' => $plan->config(),
                ];
                $exitCode = 0;
            } catch (ExceptionCompilationException $exception) {
                $report = [
                    'passed' => false,
                    'error' => $exception->getMessage(),
                ];
                $exitCode = 1;
            }

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report,
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('Exceptions validate:');
                $output->writeln(sprintf('  Passed: %s', ($report['passed'] ?? false) ? 'yes' : 'no'));

                if (($report['passed'] ?? false) === true) {
                    $output->writeln(sprintf('  Environment: %s', $report['environment']));
                    $output->writeln(sprintf('  Runtime: %s', $report['runtime']));
                    $output->writeln(sprintf('  Debug: %s', $report['debug'] ? 'yes' : 'no'));
                    $output->writeln(sprintf('  Fingerprint: %s', $report['fingerprint']));
                    $output->writeln(sprintf('  Policy revision: %s', $report['policy_revision']));
                    $output->writeln(sprintf('  PHP runtime version: %s', $report['php_runtime_version']));
                } else {
                    $output->writeln(sprintf('  Error: %s', $report['error']));
                }
            }

            return $exitCode;
        });
    }
}
