<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

/**
 * Agrupación de checks ejecutados contra un runtime driver en una plataforma concreta.
 *
 * Este DTO NO publica nada por sí mismo: es la entrada de la capa productora.
 * El publisher se encarga de traducir los checks a un RuntimeCapabilities con nivel
 * de evidencia adecuado y persistirlo en RuntimeCapabilityEvidenceStore.
 *
 * @param list<RuntimeCapabilityVerificationCheck> $checks
 * @param array<string, mixed> $metadata
 */
final readonly class RuntimeCapabilityVerificationReport
{
    /**
     * @param list<RuntimeCapabilityVerificationCheck> $checks
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $driver,
        private string $platform,
        private string $profile = 'release',
        private int $executedAt = 0,
        private array $checks = [],
        private array $metadata = [],
    ) {
    }

    public function driver(): string
    {
        return strtolower(trim($this->driver));
    }

    public function platform(): string
    {
        $platform = trim($this->platform);

        return $platform === '' ? 'unspecified' : $platform;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function executedAt(): int
    {
        return $this->executedAt > 0 ? $this->executedAt : time();
    }

    /**
     * @return list<RuntimeCapabilityVerificationCheck>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function totalChecks(): int
    {
        return count($this->checks);
    }

    public function passedChecks(): int
    {
        $count = 0;

        foreach ($this->checks as $check) {
            if ($check->passed()) {
                $count++;
            }
        }

        return $count;
    }

    public function failedChecks(): int
    {
        return $this->totalChecks() - $this->passedChecks();
    }

    /**
     * Un reporte de verificación se considera válido cuando tiene al menos un check
     * y todos los checks ejecutados han pasado.
     */
    public function valid(): bool
    {
        return $this->totalChecks() > 0 && $this->failedChecks() === 0;
    }

    /**
     * Determina si este reporte sustenta que la integración nativa se verificó.
     *
     * Se requiere que el reporte sea válido y que al menos un check pertenezca
     * a la familia de integraciones nativas (ids que arrancan por http.native.,
     * loop.native., worker.native. o signal.native.).
     */
    public function provesNativeIntegration(): bool
    {
        if (! $this->valid()) {
            return false;
        }

        foreach ($this->checks as $check) {
            if (preg_match('/^(http\.native|loop\.native|worker\.native|signal\.native|adapter\.native)\b/', $check->id()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deriva un nivel de evidencia operativa desde los checks ejecutados.
     *
     * Valores posibles:
     *   - native-verified: reporte válido y prueba integración nativa.
     *   - contractual:    reporte con checks, pero no alcanza para certificar nativo.
     *   - simulated:      reporte con cero checks o con fallos (no debe publicarse como evidencia real).
     */
    public function evidenceLevel(): string
    {
        if ($this->provesNativeIntegration()) {
            return 'native-verified';
        }

        if ($this->totalChecks() > 0) {
            return 'contractual';
        }

        return 'simulated';
    }

    /**
     * @return list<string>
     */
    public function evidenceNotes(): array
    {
        $notes = [];
        $notes[] = sprintf(
            'Verification report: %d/%d checks passed en platform=%s profile=%s executed_at=%s.',
            $this->passedChecks(),
            $this->totalChecks(),
            $this->platform(),
            $this->profile(),
            date('c', $this->executedAt()),
        );

        foreach ($this->checks as $check) {
            $status = $check->passed() ? 'PASS' : 'FAIL';
            $line = sprintf('[%s] %s: %s', $status, $check->id(), $check->description());

            if ($check->durationMs() !== null) {
                $line .= sprintf(' (%.3f ms)', $check->durationMs());
            }

            $notes[] = $line;

            foreach ($check->notes() as $note) {
                $notes[] = sprintf('    > %s', $note);
            }
        }

        return $notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'platform' => $this->platform(),
            'profile' => $this->profile(),
            'executed_at' => $this->executedAt(),
            'valid' => $this->valid(),
            'proves_native_integration' => $this->provesNativeIntegration(),
            'evidence_level' => $this->evidenceLevel(),
            'total_checks' => $this->totalChecks(),
            'passed_checks' => $this->passedChecks(),
            'failed_checks' => $this->failedChecks(),
            'checks' => array_map(
                static fn (RuntimeCapabilityVerificationCheck $check): array => $check->toArray(),
                $this->checks,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Construye un reporte a partir de un payload JSON estable (ingesta).
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var list<RuntimeCapabilityVerificationCheck> $checks */
        $checks = [];

        $rawChecks = $payload['checks'] ?? [];

        if (is_array($rawChecks)) {
            foreach ($rawChecks as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $id = trim((string) ($raw['id'] ?? ''));

                if ($id === '') {
                    continue;
                }

                $checks[] = new RuntimeCapabilityVerificationCheck(
                    id: $id,
                    description: trim((string) ($raw['description'] ?? $id)),
                    passed: (bool) ($raw['passed'] ?? false),
                    notes: isset($raw['notes']) && is_array($raw['notes'])
                        ? array_values(array_map(static fn ($note): string => trim((string) $note), $raw['notes']))
                        : [],
                    durationMs: isset($raw['duration_ms']) && is_numeric($raw['duration_ms'])
                        ? (float) $raw['duration_ms']
                        : null,
                    metadata: isset($raw['metadata']) && is_array($raw['metadata'])
                        ? $raw['metadata']
                        : [],
                );
            }
        }

        return new self(
            driver: (string) ($payload['driver'] ?? ''),
            platform: (string) ($payload['platform'] ?? ''),
            profile: (string) ($payload['profile'] ?? 'release'),
            executedAt: isset($payload['executed_at']) && is_numeric($payload['executed_at'])
                ? (int) $payload['executed_at']
                : time(),
            checks: $checks,
            metadata: isset($payload['metadata']) && is_array($payload['metadata'])
                ? $payload['metadata']
                : [],
        );
    }
}
