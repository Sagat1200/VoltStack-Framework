<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Release;

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
        string $commandName = 'exceptions:release-check',
    ): ExceptionPlanReleaseCheckReport {
        $app = $this->bootstrapApplication();
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

    private function bootstrapApplication(): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf('The application bootstrap file could not be found at [%s].', $bootstrapPath));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        return $app;
    }
}
