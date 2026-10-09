<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

final readonly class RuntimeCapabilities
{
    /**
     * @param list<string> $evidenceNotes
     */
    public function __construct(
        private bool $persistent,
        private bool $concurrent,
        private bool $streaming = false,
        private bool $drainControl = false,
        private bool $nativeHttp = false,
        private string $evidenceLevel = 'contractual',
        private bool $nativeIntegrationVerified = false,
        private array $evidenceNotes = [],
    ) {
    }

    public function persistent(): bool
    {
        return $this->persistent;
    }

    public function concurrent(): bool
    {
        return $this->concurrent;
    }

    public function streaming(): bool
    {
        return $this->streaming;
    }

    public function drainControl(): bool
    {
        return $this->drainControl;
    }

    public function nativeHttp(): bool
    {
        return $this->nativeHttp;
    }

    public function evidenceLevel(): string
    {
        return $this->evidenceLevel;
    }

    public function nativeIntegrationVerified(): bool
    {
        return $this->nativeIntegrationVerified;
    }

    /**
     * @return list<string>
     */
    public function evidenceNotes(): array
    {
        return $this->evidenceNotes;
    }

    /**
     * @return array<string, mixed>
     */
    public function evidence(): array
    {
        return [
            'level' => $this->evidenceLevel,
            'native_integration_verified' => $this->nativeIntegrationVerified,
            'notes' => $this->evidenceNotes,
        ];
    }
}
