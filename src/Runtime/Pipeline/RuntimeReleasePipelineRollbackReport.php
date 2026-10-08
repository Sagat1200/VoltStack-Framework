<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Pipeline;

final readonly class RuntimeReleasePipelineRollbackReport
{
    public function __construct(
        private bool $enabled,
        private bool $triggered,
        private ?string $reason,
        private ?string $bootstrapGenerationBefore,
        private ?string $bootstrapGenerationFailed,
        private ?string $bootstrapGenerationActive,
        private bool $bootstrapRolledBack,
        private ?string $calibrationGenerationBefore,
        private ?string $calibrationGenerationFailed,
        private ?string $calibrationGenerationActive,
        private bool $calibrationRolledBack,
    ) {
    }

    public static function idle(
        bool $enabled,
        ?string $bootstrapGenerationBefore = null,
        ?string $calibrationGenerationBefore = null,
    ): self {
        return new self(
            enabled: $enabled,
            triggered: false,
            reason: null,
            bootstrapGenerationBefore: $bootstrapGenerationBefore,
            bootstrapGenerationFailed: $bootstrapGenerationBefore,
            bootstrapGenerationActive: $bootstrapGenerationBefore,
            bootstrapRolledBack: false,
            calibrationGenerationBefore: $calibrationGenerationBefore,
            calibrationGenerationFailed: $calibrationGenerationBefore,
            calibrationGenerationActive: $calibrationGenerationBefore,
            calibrationRolledBack: false,
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function triggered(): bool
    {
        return $this->triggered;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function bootstrapRolledBack(): bool
    {
        return $this->bootstrapRolledBack;
    }

    public function calibrationRolledBack(): bool
    {
        return $this->calibrationRolledBack;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'triggered' => $this->triggered,
            'reason' => $this->reason,
            'bootstrap' => [
                'generation_before' => $this->bootstrapGenerationBefore,
                'generation_failed' => $this->bootstrapGenerationFailed,
                'generation_active' => $this->bootstrapGenerationActive,
                'rolled_back' => $this->bootstrapRolledBack,
            ],
            'calibration' => [
                'generation_before' => $this->calibrationGenerationBefore,
                'generation_failed' => $this->calibrationGenerationFailed,
                'generation_active' => $this->calibrationGenerationActive,
                'rolled_back' => $this->calibrationRolledBack,
            ],
        ];
    }
}
