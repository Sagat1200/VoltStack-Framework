<?php

declare(strict_types=1);

namespace Quantum\Config\Release;

use Quantum\Config\Telemetry\ConfigReleaseTelemetryEmitter;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class ConfigReleaseChecker
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(
        bool $requireActiveGeneration = true,
        bool $requirePublishedMatch = true,
        bool $emitTelemetry = false,
        string $commandName = 'config:release-check',
    ): ConfigReleaseCheckReport {
        $app = $this->bootstrapApplication();
        $scopeManager = $app->make(ScopeManager::class);

        return $scopeManager->runInCommand(
            function () use ($app, $requireActiveGeneration, $requirePublishedMatch, $emitTelemetry): ConfigReleaseCheckReport {
                $report = new ConfigReleaseCheckReport(
                    status: $app->configStatusInspector()->inspect($app),
                    requireActiveGeneration: $requireActiveGeneration,
                    requirePublishedMatch: $requirePublishedMatch,
                );

                if ($emitTelemetry) {
                    (new ConfigReleaseTelemetryEmitter(
                        $app->make(TelemetryManagerInterface::class),
                    ))->emit($report);
                }

                return $report;
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
