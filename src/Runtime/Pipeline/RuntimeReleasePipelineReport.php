<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Pipeline;

use Quantum\Bootstrap\Release\BootstrapReleaseCheckReport;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationArtifact;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceArtifact;
use VoltStack\Runtime\Smoke\RuntimeSmokeCheckReport;

final readonly class RuntimeReleasePipelineReport
{
    public function __construct(
        private string $driver,
        private string $profile,
        private string $capabilityEvidenceLevel,
        private bool $nativeIntegrationVerified,
        private array $capabilityEvidenceNotes,
        private ?RuntimeCapabilityEvidenceArtifact $activeCapabilityEvidence,
        private BootstrapReleaseCheckReport $releaseCheck,
        private ?RuntimeSmokeCheckReport $smokeCheck,
        private ?RuntimeBudgetCalibrationReport $calibration,
        private ?RuntimeBudgetCalibrationArtifact $publishedCalibration,
        private RuntimeReleasePipelineRollbackReport $rollback,
        private RuntimeReleasePipelineDrainReport $drain,
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function capabilityEvidenceLevel(): string
    {
        return $this->capabilityEvidenceLevel;
    }

    public function nativeIntegrationVerified(): bool
    {
        return $this->nativeIntegrationVerified;
    }

    /**
     * @return list<string>
     */
    public function capabilityEvidenceNotes(): array
    {
        return $this->capabilityEvidenceNotes;
    }

    public function activeCapabilityEvidence(): ?RuntimeCapabilityEvidenceArtifact
    {
        return $this->activeCapabilityEvidence;
    }

    public function releaseCheck(): BootstrapReleaseCheckReport
    {
        return $this->releaseCheck;
    }

    public function smokeCheck(): ?RuntimeSmokeCheckReport
    {
        return $this->smokeCheck;
    }

    public function calibration(): ?RuntimeBudgetCalibrationReport
    {
        return $this->calibration;
    }

    public function publishedCalibration(): ?RuntimeBudgetCalibrationArtifact
    {
        return $this->publishedCalibration;
    }

    public function rollback(): RuntimeReleasePipelineRollbackReport
    {
        return $this->rollback;
    }

    public function drain(): RuntimeReleasePipelineDrainReport
    {
        return $this->drain;
    }

    public function passed(): bool
    {
        if (! $this->releaseCheck->passed()) {
            return false;
        }

        if ($this->smokeCheck === null || ! $this->smokeCheck->passed()) {
            return false;
        }

        if ($this->calibration !== null && ! $this->calibration->passed()) {
            return false;
        }

        return true;
    }

    public function failedStage(): ?string
    {
        if (! $this->releaseCheck->passed()) {
            return 'bootstrap_release';
        }

        if ($this->smokeCheck === null || ! $this->smokeCheck->passed()) {
            return 'runtime_smoke';
        }

        if ($this->calibration !== null && ! $this->calibration->passed()) {
            return 'runtime_calibration';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        if (! $this->releaseCheck->passed()) {
            return $this->releaseCheck->budget()->violations();
        }

        if ($this->smokeCheck !== null && ! $this->smokeCheck->passed()) {
            return $this->smokeCheck->violations();
        }

        if ($this->calibration !== null && ! $this->calibration->passed()) {
            return array_map(
                static fn(array $failure): string => sprintf(
                    'Calibration iteration %d failed: %s',
                    $failure['iteration'] ?? 0,
                    implode(' | ', $failure['violations'] ?? []),
                ),
                $this->calibration->failures(),
            );
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'driver' => $this->driver,
            'profile' => $this->profile,
            'capability_evidence' => [
                'level' => $this->capabilityEvidenceLevel,
                'native_integration_verified' => $this->nativeIntegrationVerified,
                'notes' => $this->capabilityEvidenceNotes,
            ],
            'active_capability_evidence' => $this->activeCapabilityEvidence?->toArray(),
            'failed_stage' => $this->failedStage(),
            'release_check' => $this->releaseCheck->toArray(),
            'smoke_check' => $this->smokeCheck?->toArray(),
            'calibration' => $this->calibration?->toArray(),
            'published_calibration' => $this->publishedCalibration?->toArray(),
            'rollback' => $this->rollback->toArray(),
            'drain' => $this->drain->toArray(),
            'violations' => $this->violations(),
        ];
    }
}
