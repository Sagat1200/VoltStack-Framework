<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Smoke;

final readonly class RuntimeBootstrapReuseReport
{
    public function __construct(
        private string $artifactDirectory,
        private int $appInstanceId,
        private int $requestRunnerInstanceId,
        private ?string $generationIdBefore,
        private ?string $generationIdAfter,
        private ?string $fingerprintBefore,
        private ?string $fingerprintAfter,
    ) {
    }

    public function artifactDirectory(): string
    {
        return $this->artifactDirectory;
    }

    public function appInstanceId(): int
    {
        return $this->appInstanceId;
    }

    public function requestRunnerInstanceId(): int
    {
        return $this->requestRunnerInstanceId;
    }

    public function generationIdBefore(): ?string
    {
        return $this->generationIdBefore;
    }

    public function generationIdAfter(): ?string
    {
        return $this->generationIdAfter;
    }

    public function fingerprintBefore(): ?string
    {
        return $this->fingerprintBefore;
    }

    public function fingerprintAfter(): ?string
    {
        return $this->fingerprintAfter;
    }

    public function activeGenerationObserved(): bool
    {
        return $this->generationIdBefore !== null || $this->generationIdAfter !== null;
    }

    public function stable(): bool
    {
        if ($this->generationIdBefore === null && $this->generationIdAfter === null) {
            return true;
        }

        if ($this->generationIdBefore === null || $this->generationIdAfter === null) {
            return false;
        }

        return $this->generationIdBefore === $this->generationIdAfter
            && $this->fingerprintBefore === $this->fingerprintAfter;
    }

    public function passed(): bool
    {
        return $this->stable();
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        if ($this->generationIdBefore === null && $this->generationIdAfter === null) {
            return [];
        }

        if ($this->generationIdBefore === null && $this->generationIdAfter !== null) {
            return [
                sprintf(
                    'El smoke-check materializo una generacion bootstrap nueva durante las requests (%s).',
                    $this->generationIdAfter,
                ),
            ];
        }

        if ($this->generationIdBefore !== null && $this->generationIdAfter === null) {
            return ['La generacion bootstrap activa desaparecio durante las requests del smoke-check.'];
        }

        if ($this->generationIdBefore !== $this->generationIdAfter) {
            return [
                sprintf(
                    'La generacion bootstrap activa cambio durante las requests (%s -> %s).',
                    $this->generationIdBefore,
                    $this->generationIdAfter,
                ),
            ];
        }

        if ($this->fingerprintBefore !== $this->fingerprintAfter) {
            return ['El fingerprint de la generacion bootstrap activa cambio durante las requests del smoke-check.'];
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
            'stable' => $this->stable(),
            'artifact_directory' => $this->artifactDirectory,
            'app_instance_id' => $this->appInstanceId,
            'request_runner_instance_id' => $this->requestRunnerInstanceId,
            'active_generation_observed' => $this->activeGenerationObserved(),
            'generation_id_before' => $this->generationIdBefore,
            'generation_id_after' => $this->generationIdAfter,
            'fingerprint_before' => $this->fingerprintBefore,
            'fingerprint_after' => $this->fingerprintAfter,
            'violations' => $this->violations(),
        ];
    }
}
