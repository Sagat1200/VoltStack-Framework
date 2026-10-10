<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

/**
 * Resultado individual de un check ejecutado contra un runtime real.
 *
 * Ejemplos de check id:
 *   - loop.native.boot
 *   - http.native.status200
 *   - worker.drain.signal
 *   - reset.session.isolated
 *   - concurrency.request.non_blocking
 *
 * @param list<string> $notes
 * @param array<string, mixed> $metadata
 */
final readonly class RuntimeCapabilityVerificationCheck
{
    public function __construct(
        private string $id,
        private string $description,
        private bool $passed,
        private array $notes = [],
        private ?float $durationMs = null,
        private array $metadata = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'passed' => $this->passed,
            'duration_ms' => $this->durationMs,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ];
    }
}
