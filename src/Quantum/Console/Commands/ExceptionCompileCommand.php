<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use VoltStack\Framework\Application;

final class ExceptionCompileCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:compile';
    }

    public function description(): string
    {
        return 'Compila el plan de excepciones y lo publica atomica y verificablemente.';
    }

    public function usage(): string
    {
        return 'exceptions:compile [--check-only] [--json] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--check-only' => 'Compila y valida el plan sin publicarlo en el store activo.',
            '--json' => 'Emite un payload JSON estable con el resultado de compilacion/publicacion.',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de compilar.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(
            function (Application $app) use ($input, $output): int {
            $checkOnly = $input->hasOption('check-only');
            $rawConfig = $app->config('exceptions', []);
            $config = is_array($rawConfig) ? $rawConfig : [];

            /** @var ExceptionCompilationPlan $plan */
            $plan = $app->make(ExceptionPlanCompiler::class)->compile($config);
            $store = $app->make(ExceptionPlanStore::class);
            $artifactPath = $store->currentPath();
            $published = false;
            $verified = false;

            if (! $checkOnly) {
                $artifactPath = $store->persist($plan);
                $published = true;

                $reloaded = $store->load();

                if (! $reloaded instanceof ExceptionCompilationPlan) {
                    throw new ExceptionCompilationException(sprintf(
                        'Published exception compilation plan could not be reloaded from [%s].',
                        $artifactPath,
                    ));
                }

                if ($reloaded->fingerprint() !== $plan->fingerprint()) {
                    throw new ExceptionCompilationException(sprintf(
                        'Published exception compilation plan fingerprint [%s] does not match compiled fingerprint [%s].',
                        $reloaded->fingerprint(),
                        $plan->fingerprint(),
                    ));
                }

                $reloaded->assertCompatibleWith(
                    expectedRuntime: $plan->runtime(),
                    expectedPhpRuntimeVersion: PHP_VERSION,
                );
                $verified = true;
            }

            $report = [
                'passed' => true,
                'check_only' => $checkOnly,
                'published' => $published,
                'verified' => $verified,
                'artifact_path' => $artifactPath,
                'environment' => $plan->environment(),
                'runtime' => $plan->runtime(),
                'php_runtime_version' => $plan->phpRuntimeVersion(),
                'fingerprint' => $plan->fingerprint(),
                'policy_revision' => $plan->policyRevision(),
                'reporters' => $plan->reporterIds(),
                'spa_versions' => $plan->spaVersions(),
            ];

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report,
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('Exceptions compile:');
                $output->writeln(sprintf('  Passed: %s', $report['passed'] ? 'yes' : 'no'));
                $output->writeln(sprintf('  Check only: %s', $checkOnly ? 'yes' : 'no'));
                $output->writeln(sprintf('  Published: %s', $published ? 'yes' : 'no'));
                $output->writeln(sprintf('  Verified: %s', $verified ? 'yes' : 'no'));
                $output->writeln(sprintf('  Environment: %s', $plan->environment()));
                $output->writeln(sprintf('  Runtime: %s', $plan->runtime()));
                $output->writeln(sprintf('  PHP runtime version: %s', $plan->phpRuntimeVersion()));
                $output->writeln(sprintf('  Fingerprint: %s', $plan->fingerprint()));
                $output->writeln(sprintf('  Policy revision: %s', $plan->policyRevision()));
                $output->writeln(sprintf('  Artifact path: %s', $artifactPath));
                $output->writeln(sprintf(
                    '  Reporters: %s',
                    $plan->reporterIds() !== [] ? implode(', ', $plan->reporterIds()) : '-',
                ));
                $output->writeln(sprintf(
                    '  SPA versions: %s',
                    $plan->spaVersions() !== [] ? implode(', ', array_map(static fn (int $version): string => (string) $version, $plan->spaVersions())) : '-',
                ));
            }

            return 0;
        },
            requirePublishedConfig: $input->hasOption('require-published-config'),
        );
    }
}
