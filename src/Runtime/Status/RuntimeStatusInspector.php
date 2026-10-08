<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Status;

use InvalidArgumentException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationArtifact;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetBaselineResolver;
use VoltStack\Runtime\RuntimeManager;

final class RuntimeStatusInspector
{
    public function inspect(Application $app, ?string $driver = null, int $maxRequests = 1): RuntimeStatusReport
    {
        /** @var RuntimeManager $manager */
        $manager = $app->make(RuntimeManager::class);
        $driver = strtolower(trim($driver ?? (string) $app->config('runtime.driver', 'frankenphp')));
        $alerts = [];
        $activeCalibration = $this->activeCalibration($app, $driver);

        try {
            $adapter = $manager->adapter($driver);
            $capabilities = $adapter->capabilities();
            $recommendedBudget = (new RuntimeBudgetBaselineResolver())->resolve($app, $driver, $capabilities);
        } catch (InvalidArgumentException) {
            return new RuntimeStatusReport(
                driver: $driver === '' ? 'unknown' : $driver,
                maxRequests: max(1, $maxRequests),
                persistent: false,
                concurrent: false,
                streaming: false,
                drainControl: false,
                nativeHttp: false,
                recommendedBudget: new RuntimeBudgetBaseline(
                    driver: $driver === '' ? 'unknown' : $driver,
                    totalMaximumMs: null,
                    requestMaximumMs: null,
                    source: 'unavailable',
                ),
                rolloutReadiness: new RuntimeRolloutReadinessReport(
                    ready: false,
                    strategy: 'blocked',
                    budgetSource: 'unavailable',
                    requiresDrainControl: false,
                    requiresEmpiricalBudget: false,
                    gaps: ['El driver runtime solicitado no esta registrado.'],
                ),
                activeCalibration: $activeCalibration,
                supportedDrivers: $manager->drivers(),
                alerts: ['El driver runtime solicitado no esta registrado.'],
            );
        }

        if ($maxRequests < 1) {
            $alerts[] = 'maxRequests debe ser mayor o igual a 1.';
        }

        return new RuntimeStatusReport(
            driver: $driver,
            maxRequests: max(1, $maxRequests),
            persistent: $capabilities->persistent(),
            concurrent: $capabilities->concurrent(),
            streaming: $capabilities->streaming(),
            drainControl: $capabilities->drainControl(),
            nativeHttp: $capabilities->nativeHttp(),
            recommendedBudget: $recommendedBudget,
            rolloutReadiness: $this->rolloutReadiness(
                persistent: $capabilities->persistent(),
                drainControl: $capabilities->drainControl(),
                budget: $recommendedBudget,
                hasActiveCalibration: $activeCalibration !== null,
            ),
            activeCalibration: $activeCalibration,
            supportedDrivers: $manager->drivers(),
            alerts: $alerts,
        );
    }

    private function activeCalibration(Application $app, string $driver): ?RuntimeBudgetCalibrationArtifact
    {
        $artifact = (new RuntimeBudgetCalibrationStoreResolver())
            ->resolveForDriver($app, $driver)
            ->currentArtifact();

        if ($artifact === null) {
            return null;
        }

        return strtolower($artifact->driver()) === strtolower($driver)
            ? $artifact
            : null;
    }

    private function rolloutReadiness(
        bool $persistent,
        bool $drainControl,
        RuntimeBudgetBaseline $budget,
        bool $hasActiveCalibration,
    ): RuntimeRolloutReadinessReport {
        $strategy = $this->rolloutStrategy($persistent, $drainControl);
        $requiresEmpiricalBudget = $persistent;
        $gaps = [];

        if ($persistent && ! $drainControl) {
            $gaps[] = 'El runtime persistente no expone drain control; el rollout debe asumir reemplazo completo.';
        }

        if (
            $requiresEmpiricalBudget
            && ! in_array($budget->source(), ['config', 'published-calibration'], true)
            && ! $hasActiveCalibration
        ) {
            $gaps[] = 'No existe budget runtime configurado o calibrado para un rollout persistente controlado.';
        }

        return new RuntimeRolloutReadinessReport(
            ready: $gaps === [],
            strategy: $strategy,
            budgetSource: $budget->source(),
            requiresDrainControl: $persistent,
            requiresEmpiricalBudget: $requiresEmpiricalBudget,
            gaps: $gaps,
        );
    }

    private function rolloutStrategy(bool $persistent, bool $drainControl): string
    {
        if (! $persistent) {
            return 'single-step';
        }

        if ($drainControl) {
            return 'progressive-drain';
        }

        return 'replace-all';
    }
}
