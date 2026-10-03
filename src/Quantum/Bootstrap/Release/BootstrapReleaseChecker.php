<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Release;

use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Bootstrap\Budget\BootstrapBudgetEvaluator;
use Quantum\Bootstrap\Config\BootstrapConfiguration;
use Quantum\Bootstrap\Context\BootstrapContext;
use RuntimeException;
use VoltStack\Framework\Application;

final class BootstrapReleaseChecker
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(
        string $profile = 'release',
        ?string $artifactDirectory = null,
        ?BootstrapBudget $budget = null,
        bool $emitPhaseTelemetry = false,
    ): BootstrapReleaseCheckReport {
        $sourceApp = $this->bootstrapCurrentApplication();
        $environment = (string) $sourceApp->config('app.env', 'local');
        $runtimeDriver = (string) $sourceApp->config('runtime.driver', 'frankenphp');
        $providers = array_values(array_filter(
            (array) $sourceApp->config('app.providers', []),
            static fn(mixed $provider): bool => is_string($provider) && trim($provider) !== '',
        ));

        $artifactDirectory = $this->normalizeArtifactDirectory($artifactDirectory);
        $targetApp = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($targetApp);

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment($environment)
            ->withProfile($profile)
            ->withProviders($providers)
            ->withArtifactDirectory($artifactDirectory)
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                structural: [
                    'runtime' => ['driver' => $runtimeDriver],
                ],
                operational: [
                    'emit_phase_telemetry' => $emitPhaseTelemetry,
                ],
            ))
            ->build();

        $result = $bootstrapper->bootPlan($plan, BootstrapContext::forConsole(
            environment: $environment,
            artifactPolicy: 'prefer-artifacts',
            executionMode: 'release-check',
            profile: $profile,
        ));

        $budgetReport = (new BootstrapBudgetEvaluator())->evaluate(
            $result,
            $budget ?? BootstrapBudget::fromValues(),
        );

        return new BootstrapReleaseCheckReport(
            result: $result,
            budget: $budgetReport,
            profile: $profile,
        );
    }

    private function bootstrapCurrentApplication(): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf(
                'The application bootstrap file could not be found at [%s].',
                $bootstrapPath,
            ));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        return $app;
    }

    private function normalizeArtifactDirectory(?string $artifactDirectory): string
    {
        $artifactDirectory = trim((string) $artifactDirectory);

        if ($artifactDirectory === '') {
            return $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifactDirectory);

        if ($this->isAbsolutePath($normalized)) {
            return rtrim($normalized, '\\/');
        }

        return rtrim($this->basePath . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/'), '\\/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
