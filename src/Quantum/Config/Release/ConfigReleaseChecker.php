<?php

declare(strict_types=1);

namespace Quantum\Config\Release;

use Quantum\Config\Publication\PublishedConfigurationRequiredException;
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
        bool $requirePublishedConfig = false,
        bool $emitTelemetry = false,
        string $commandName = 'config:release-check',
    ): ConfigReleaseCheckReport {
        $app = $this->bootstrapApplication($requirePublishedConfig);
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
                'Published configuration is required for config release check, but no active configuration generation exists.',
            );
        }

        if (! $status->publishedMatchesEffective()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for config release check, but the effective snapshot differs from the active generation.',
            );
        }
    }
}
