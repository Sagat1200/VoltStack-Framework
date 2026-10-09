<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Status;

use InvalidArgumentException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationArtifact;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetBaselineResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceArtifact;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\RuntimeCapabilities;
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
        $activeCapabilityEvidence = $this->activeCapabilityEvidence($app, $driver);

        try {
            $adapter = $manager->adapter($driver);
            $capabilities = $this->effectiveCapabilities(
                $adapter->capabilities(),
                $activeCapabilityEvidence,
            );
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
                capabilityEvidenceLevel: 'unavailable',
                nativeIntegrationVerified: false,
                capabilityEvidenceNotes: ['No existe un adapter runtime registrado para este driver.'],
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
                activeCapabilityEvidence: $activeCapabilityEvidence,
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
            capabilityEvidenceLevel: $capabilities->evidenceLevel(),
            nativeIntegrationVerified: $capabilities->nativeIntegrationVerified(),
            capabilityEvidenceNotes: $capabilities->evidenceNotes(),
            recommendedBudget: $recommendedBudget,
            rolloutReadiness: $this->rolloutReadiness(
                persistent: $capabilities->persistent(),
                drainControl: $capabilities->drainControl(),
                budget: $recommendedBudget,
                hasActiveCalibration: $activeCalibration !== null,
                evidenceLevel: $capabilities->evidenceLevel(),
                nativeIntegrationVerified: $capabilities->nativeIntegrationVerified(),
            ),
            activeCalibration: $activeCalibration,
            activeCapabilityEvidence: $activeCapabilityEvidence,
            supportedDrivers: $manager->drivers(),
            alerts: [
                ...$alerts,
                ...$this->capabilityEvidenceAlerts($adapter->capabilities(), $activeCapabilityEvidence),
            ],
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

    private function activeCapabilityEvidence(Application $app, string $driver): ?RuntimeCapabilityEvidenceArtifact
    {
        $artifact = (new RuntimeCapabilityEvidenceStoreResolver())
            ->resolveForDriver($app, $driver)
            ->currentArtifact();

        if ($artifact === null) {
            return null;
        }

        return strtolower($artifact->driver()) === strtolower($driver)
            ? $artifact
            : null;
    }

    private function effectiveCapabilities(
        RuntimeCapabilities $capabilities,
        ?RuntimeCapabilityEvidenceArtifact $artifact,
    ): RuntimeCapabilities {
        if ($artifact === null) {
            return $capabilities;
        }

        $evidence = $artifact->capabilities();

        return new RuntimeCapabilities(
            persistent: $capabilities->persistent(),
            concurrent: $capabilities->concurrent(),
            streaming: $capabilities->streaming(),
            drainControl: $capabilities->drainControl(),
            nativeHttp: $capabilities->nativeHttp(),
            evidenceLevel: $evidence->evidenceLevel(),
            nativeIntegrationVerified: $evidence->nativeIntegrationVerified(),
            evidenceNotes: $evidence->evidenceNotes(),
        );
    }

    /**
     * @return list<string>
     */
    private function capabilityEvidenceAlerts(
        RuntimeCapabilities $adapterCapabilities,
        ?RuntimeCapabilityEvidenceArtifact $artifact,
    ): array {
        if ($artifact === null) {
            return [];
        }

        $snapshot = $artifact->capabilities();
        $alerts = [];

        if ($adapterCapabilities->persistent() !== $snapshot->persistent()) {
            $alerts[] = 'La evidencia activa no coincide con persistent del adapter runtime.';
        }

        if ($adapterCapabilities->concurrent() !== $snapshot->concurrent()) {
            $alerts[] = 'La evidencia activa no coincide con concurrent del adapter runtime.';
        }

        if ($adapterCapabilities->streaming() !== $snapshot->streaming()) {
            $alerts[] = 'La evidencia activa no coincide con streaming del adapter runtime.';
        }

        if ($adapterCapabilities->drainControl() !== $snapshot->drainControl()) {
            $alerts[] = 'La evidencia activa no coincide con drain control del adapter runtime.';
        }

        if ($adapterCapabilities->nativeHttp() !== $snapshot->nativeHttp()) {
            $alerts[] = 'La evidencia activa no coincide con native HTTP del adapter runtime.';
        }

        return $alerts;
    }

    private function rolloutReadiness(
        bool $persistent,
        bool $drainControl,
        RuntimeBudgetBaseline $budget,
        bool $hasActiveCalibration,
        string $evidenceLevel,
        bool $nativeIntegrationVerified,
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

        if ($persistent && (! $nativeIntegrationVerified || $evidenceLevel !== 'native-verified')) {
            $gaps[] = 'El runtime persistente aun no tiene evidencia nativa verificada en esta plataforma.';
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
