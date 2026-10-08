<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Pipeline;

use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Bootstrap\Release\BootstrapReleaseChecker;
use Quantum\Compilation\Build;
use Quantum\Compilation\BuildManifest;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudget;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationArtifact;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrator;
use VoltStack\Runtime\RuntimeManager;
use VoltStack\Runtime\Smoke\RuntimeSmokeCheckReport;
use VoltStack\Runtime\Smoke\RuntimeSmokeChecker;

final class RuntimeReleasePipelineRunner
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param list<string> $requestDefinitions
     */
    public function run(
        ?string $driver = null,
        string $profile = 'release',
        array $requestDefinitions = [],
        ?string $artifactDirectory = null,
        ?BootstrapBudget $bootstrapBudget = null,
        ?RuntimeBudget $runtimeBudget = null,
        bool $requirePublishedConfig = false,
        bool $publishCalibration = false,
        int $warmupIterations = 2,
        int $measuredIterations = 10,
        float $safetyMultiplier = 1.25,
        ?string $calibrationDirectory = null,
        bool $rollbackOnFailure = true,
        bool $emitPhaseTelemetry = false,
    ): RuntimeReleasePipelineReport {
        $app = $this->bootstrapCurrentApplication($requirePublishedConfig);
        $driver = $this->resolveDriver($app, $driver);
        $artifactDirectory = $this->normalizeArtifactDirectory($artifactDirectory);
        $bootstrapBefore = $this->currentBootstrapBuild($artifactDirectory);
        $calibrationBefore = $this->currentCalibrationBuild($app, $driver, $calibrationDirectory);

        $rollback = RuntimeReleasePipelineRollbackReport::idle(
            enabled: $rollbackOnFailure,
            bootstrapGenerationBefore: $bootstrapBefore?->id,
            calibrationGenerationBefore: $calibrationBefore?->id,
        );
        $drain = RuntimeReleasePipelineDrainReport::idle(
            supported: $this->supportsDrainControl($app, $driver),
        );

        $releaseCheck = (new BootstrapReleaseChecker($this->basePath))->run(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            budget: $bootstrapBudget,
            emitPhaseTelemetry: $emitPhaseTelemetry,
            requirePublishedConfig: $requirePublishedConfig,
        );

        if (! $releaseCheck->passed()) {
            return new RuntimeReleasePipelineReport(
                driver: $driver,
                profile: $profile,
                releaseCheck: $releaseCheck,
                smokeCheck: null,
                calibration: null,
                publishedCalibration: null,
                rollback: $this->rollbackForFailure(
                    app: $app,
                    driver: $driver,
                    artifactDirectory: $artifactDirectory,
                    calibrationDirectory: $calibrationDirectory,
                    reason: 'bootstrap_release',
                    rollbackOnFailure: $rollbackOnFailure,
                    bootstrapBefore: $bootstrapBefore,
                    calibrationBefore: $calibrationBefore,
                ),
                drain: $this->drainForFailure($app, $driver, 'bootstrap_release'),
            );
        }

        $smokeCheck = (new RuntimeSmokeChecker($this->basePath))->run(
            driver: $driver,
            profile: $profile,
            requestDefinitions: $requestDefinitions,
            budget: $runtimeBudget,
            artifactDirectory: $artifactDirectory,
            requirePublishedConfig: $requirePublishedConfig,
        );

        if (! $smokeCheck->passed()) {
            return new RuntimeReleasePipelineReport(
                driver: $driver,
                profile: $profile,
                releaseCheck: $releaseCheck,
                smokeCheck: $smokeCheck,
                calibration: null,
                publishedCalibration: null,
                rollback: $this->rollbackForFailure(
                    app: $app,
                    driver: $driver,
                    artifactDirectory: $artifactDirectory,
                    calibrationDirectory: $calibrationDirectory,
                    reason: 'runtime_smoke',
                    rollbackOnFailure: $rollbackOnFailure,
                    bootstrapBefore: $bootstrapBefore,
                    calibrationBefore: $calibrationBefore,
                ),
                drain: $this->drainForFailure($app, $driver, 'runtime_smoke'),
            );
        }

        $calibration = null;
        $publishedCalibration = null;

        if ($publishCalibration) {
            $calibration = (new RuntimeBudgetCalibrator($this->basePath))->run(
                driver: $driver,
                profile: $profile,
                requestDefinitions: $requestDefinitions,
                warmupIterations: $warmupIterations,
                measuredIterations: $measuredIterations,
                safetyMultiplier: $safetyMultiplier,
                artifactDirectory: $artifactDirectory,
                requirePublishedConfig: $requirePublishedConfig,
            );

            if (! $calibration->passed()) {
                return new RuntimeReleasePipelineReport(
                    driver: $driver,
                    profile: $profile,
                    releaseCheck: $releaseCheck,
                    smokeCheck: $smokeCheck,
                    calibration: $calibration,
                    publishedCalibration: null,
                    rollback: $this->rollbackForFailure(
                        app: $app,
                        driver: $driver,
                        artifactDirectory: $artifactDirectory,
                        calibrationDirectory: $calibrationDirectory,
                        reason: 'runtime_calibration',
                        rollbackOnFailure: $rollbackOnFailure,
                        bootstrapBefore: $bootstrapBefore,
                        calibrationBefore: $calibrationBefore,
                    ),
                    drain: $this->drainForFailure($app, $driver, 'runtime_calibration'),
                );
            }

            $publishedCalibration = $this->publishCalibration(
                app: $app,
                driver: $driver,
                calibration: $calibration,
                calibrationDirectory: $calibrationDirectory,
            );
        }

        return new RuntimeReleasePipelineReport(
            driver: $driver,
            profile: $profile,
            releaseCheck: $releaseCheck,
            smokeCheck: $smokeCheck,
            calibration: $calibration,
            publishedCalibration: $publishedCalibration,
            rollback: $rollback,
            drain: $drain,
        );
    }

    private function bootstrapCurrentApplication(bool $requirePublishedConfig = false): Application
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
                'Published configuration is required for runtime release pipeline, but no active configuration generation exists.',
            );
        }

        if (! $status->publishedMatchesEffective()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for runtime release pipeline, but the effective snapshot differs from the active generation.',
            );
        }
    }

    private function resolveDriver(Application $app, ?string $driver): string
    {
        $driver = strtolower(trim($driver ?? (string) $app->config('runtime.driver', 'frankenphp')));

        /** @var RuntimeManager $manager */
        $manager = $app->make(RuntimeManager::class);
        $manager->adapter($driver);

        return $driver;
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

    private function currentBootstrapBuild(string $artifactDirectory): ?Build
    {
        return (new BuildManifest($artifactDirectory))->current();
    }

    private function currentCalibrationBuild(Application $app, string $driver, ?string $calibrationDirectory): ?Build
    {
        $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver(
            $app,
            $driver,
            $calibrationDirectory,
        );

        return (new BuildManifest($store->rootPath()))->current();
    }

    private function publishCalibration(
        Application $app,
        string $driver,
        RuntimeBudgetCalibrationReport $calibration,
        ?string $calibrationDirectory,
    ): RuntimeBudgetCalibrationArtifact {
        $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver(
            $app,
            $driver,
            $calibrationDirectory,
        );
        $artifact = $store->publish($calibration);
        $store->activateGeneration($artifact->generationId());

        return $store->currentArtifact() ?? $artifact;
    }

    private function rollbackForFailure(
        Application $app,
        string $driver,
        string $artifactDirectory,
        ?string $calibrationDirectory,
        string $reason,
        bool $rollbackOnFailure,
        ?Build $bootstrapBefore,
        ?Build $calibrationBefore,
    ): RuntimeReleasePipelineRollbackReport {
        $bootstrapFailed = $this->currentBootstrapBuild($artifactDirectory);
        $bootstrapActive = $bootstrapFailed;
        $bootstrapRolledBack = false;

        if ($rollbackOnFailure && $bootstrapBefore !== null && $bootstrapFailed?->id !== $bootstrapBefore->id) {
            $store = new BootstrapManifestStore(
                manifest: new BuildManifest($artifactDirectory),
                storageRoot: $artifactDirectory,
            );
            $rolled = $store->rollback();

            if ($rolled !== null) {
                $bootstrapActive = $rolled;
                $bootstrapRolledBack = $rolled->id === $bootstrapBefore->id;
            }
        }

        $calibrationFailed = $this->currentCalibrationBuild($app, $driver, $calibrationDirectory);
        $calibrationActive = $calibrationFailed;
        $calibrationRolledBack = false;

        if ($rollbackOnFailure && $calibrationBefore !== null && $calibrationFailed?->id !== $calibrationBefore->id) {
            $store = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver(
                $app,
                $driver,
                $calibrationDirectory,
            );
            $rolled = $store->rollback();

            if ($rolled !== null) {
                $calibrationActive = $rolled;
                $calibrationRolledBack = $rolled->id === $calibrationBefore->id;
            }
        }

        return new RuntimeReleasePipelineRollbackReport(
            enabled: $rollbackOnFailure,
            triggered: true,
            reason: $reason,
            bootstrapGenerationBefore: $bootstrapBefore?->id,
            bootstrapGenerationFailed: $bootstrapFailed?->id,
            bootstrapGenerationActive: $bootstrapActive?->id,
            bootstrapRolledBack: $bootstrapRolledBack,
            calibrationGenerationBefore: $calibrationBefore?->id,
            calibrationGenerationFailed: $calibrationFailed?->id,
            calibrationGenerationActive: $calibrationActive?->id,
            calibrationRolledBack: $calibrationRolledBack,
        );
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private function supportsDrainControl(Application $app, string $driver): bool
    {
        return $this->capabilities($app, $driver)->drainControl();
    }

    private function drainForFailure(
        Application $app,
        string $driver,
        string $failedStage,
    ): RuntimeReleasePipelineDrainReport {
        $capabilities = $this->capabilities($app, $driver);

        if ($failedStage === 'bootstrap_release' || ! $capabilities->persistent()) {
            return RuntimeReleasePipelineDrainReport::idle(
                supported: $capabilities->drainControl(),
            );
        }

        return new RuntimeReleasePipelineDrainReport(
            supported: $capabilities->drainControl(),
            required: true,
            action: $capabilities->drainControl() ? 'drain' : 'terminate',
            reason: sprintf(
                'El pipeline fallo en %s sobre un runtime persistente.',
                $failedStage,
            ),
            failedStage: $failedStage,
        );
    }

    private function capabilities(Application $app, string $driver): \VoltStack\Runtime\RuntimeCapabilities
    {
        /** @var RuntimeManager $manager */
        $manager = $app->make(RuntimeManager::class);

        return $manager->adapter($driver)->capabilities();
    }
}
