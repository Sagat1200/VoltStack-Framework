<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Release;

use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Exceptions\Diagnostics\ExceptionPlanStatusInspector;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class ExceptionPlanReleaseChecker
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(
        bool $requirePublishedPlan = true,
        bool $requirePublishedMatch = true,
        bool $requireCompatiblePlan = true,
        bool $requirePublishedConfig = false,
        string $commandName = 'exceptions:release-check',
    ): ExceptionPlanReleaseCheckReport {
        $app = $this->bootstrapApplication($requirePublishedConfig);
        $scopeManager = $app->make(ScopeManager::class);

        return $scopeManager->runInCommand(
            function () use ($app, $requirePublishedPlan, $requirePublishedMatch, $requireCompatiblePlan): ExceptionPlanReleaseCheckReport {
                return new ExceptionPlanReleaseCheckReport(
                    status: (new ExceptionPlanStatusInspector())->inspect($app),
                    requirePublishedPlan: $requirePublishedPlan,
                    requirePublishedMatch: $requirePublishedMatch,
                    requireCompatiblePlan: $requireCompatiblePlan,
                );
            },
            $commandName,
        );
    }

    private function bootstrapApplication(bool $requirePublishedConfig = false): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf('The application bootstrap file could not be found at [%s].', $bootstrapPath));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        if ($requirePublishedConfig) {
            $this->assertPublishedConfiguration($app);
        }

        return $app;
    }

    private function assertPublishedConfiguration(Application $app): void
    {
        $status = $app->configStatusInspector()->inspect($app);

        if (! $status->hasActiveGeneration()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for exception release check, but no active configuration generation exists.',
            );
        }

        if (! $status->publishedMatchesEffective()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for exception release check, but the effective snapshot differs from the active generation.',
            );
        }
    }
}
