<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Pipeline;

final readonly class RuntimeReleasePipelineDrainReport
{
    public function __construct(
        private bool $supported,
        private bool $required,
        private string $action,
        private ?string $reason,
        private ?string $failedStage,
    ) {
    }

    public static function idle(bool $supported): self
    {
        return new self(
            supported: $supported,
            required: false,
            action: 'none',
            reason: null,
            failedStage: null,
        );
    }

    public function supported(): bool
    {
        return $this->supported;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function failedStage(): ?string
    {
        return $this->failedStage;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'supported' => $this->supported,
            'required' => $this->required,
            'action' => $this->action,
            'reason' => $this->reason,
            'failed_stage' => $this->failedStage,
        ];
    }
}
