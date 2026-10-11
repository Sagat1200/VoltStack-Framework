<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

/**
 * Reporte del resultado de una operación de drain sobre un worker/runtime
 * persistente (FrankenPHP, RoadRunner, OpenSwoole).
 *
 * Este DTO NO ejecuta el drain: es la salida estructurada que producen los
 * scripts de drain en `scripts/runtime/*-drain.ps1` (o un futuro comando CLI)
 * para que el operador/CI tenga un artefacto JSON consumible y trazable.
 *
 * El drain es la operación que indica al worker que deje de aceptar nuevas
 * solicitudes, complete las en vuelo y se detenga ordenadamente. Si el plazo
 * se agota, se aplica un force-kill y el reporte lo refleja.
 *
 * Shape JSON objetivo (consumible por fromArray):
 *   {
 *     "driver": "frankenphp",
 *     "platform": "win-frankenphp-x64",
 *     "profile": "release",
 *     "executed_at": 1728000000,
 *     "drain_strategy": "sigterm",
 *     "outcome": "success",
 *     "graceful": true,
 *     "duration_ms": 1234.5,
 *     "timeout_ms": 30000,
 *     "active_requests_before": 12,
 *     "active_requests_after": 0,
 *     "error_message": null,
 *     "metadata": { "source": "frankenphp-real-drain", "pid": 1234 }
 *   }
 */
final readonly class RuntimeDrainReport
{
    /**
     * Estrategias de drain soportadas por los runners certificados.
     */
    public const STRATEGY_SIGTERM = 'sigterm';

    public const STRATEGY_RR_RESET = 'rr-reset';

    public const STRATEGY_SWOOLE_SHUTDOWN = 'swoole-shutdown';

    public const STRATEGY_SAPI_EXIT = 'sapi-exit';

    /**
     * Resultados posibles de una operación de drain.
     */
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_TIMEOUT = 'timeout';

    public const OUTCOME_FORCE_KILL = 'force-kill';

    public const OUTCOME_ERROR = 'error';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $driver,
        private string $platform,
        private string $profile = 'release',
        private int $executedAt = 0,
        private string $drainStrategy = self::STRATEGY_SIGTERM,
        private string $outcome = self::OUTCOME_SUCCESS,
        private bool $graceful = true,
        private ?float $durationMs = null,
        private ?int $timeoutMs = null,
        private ?int $activeRequestsBefore = null,
        private ?int $activeRequestsAfter = null,
        private ?string $errorMessage = null,
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

    public function drainStrategy(): string
    {
        return $this->drainStrategy;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function graceful(): bool
    {
        return $this->graceful;
    }

    public function durationMs(): ?float
    {
        return $this->durationMs;
    }

    public function timeoutMs(): ?int
    {
        return $this->timeoutMs;
    }

    public function activeRequestsBefore(): ?int
    {
        return $this->activeRequestsBefore;
    }

    public function activeRequestsAfter(): ?int
    {
        return $this->activeRequestsAfter;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * Un drain se considera válido cuando el resultado es uno de los outcomes
     * conocidos y, si fue success/timeout, tiene duración registrada.
     */
    public function valid(): bool
    {
        $known = in_array($this->outcome, [
            self::OUTCOME_SUCCESS,
            self::OUTCOME_TIMEOUT,
            self::OUTCOME_FORCE_KILL,
            self::OUTCOME_ERROR,
        ], true);

        if (! $known) {
            return false;
        }

        if ($this->outcome === self::OUTCOME_SUCCESS || $this->outcome === self::OUTCOME_TIMEOUT) {
            return $this->durationMs !== null && $this->durationMs >= 0;
        }

        return true;
    }

    /**
     * Indica si el drain se completó de forma ordenada (sin force-kill ni error).
     */
    public function drainedGracefully(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS && $this->graceful;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];
        $notes[] = sprintf(
            'Drain report: driver=%s platform=%s profile=%s strategy=%s outcome=%s graceful=%s executed_at=%s.',
            $this->driver(),
            $this->platform(),
            $this->profile(),
            $this->drainStrategy,
            $this->outcome,
            $this->graceful ? 'yes' : 'no',
            date('c', $this->executedAt()),
        );

        if ($this->durationMs !== null) {
            $notes[] = sprintf('Duration: %.3f ms (timeout: %s ms).', $this->durationMs, $this->timeoutMs ?? 'n/a');
        }

        if ($this->activeRequestsBefore !== null || $this->activeRequestsAfter !== null) {
            $notes[] = sprintf(
                'Active requests: before=%s after=%s.',
                $this->activeRequestsBefore ?? 'n/a',
                $this->activeRequestsAfter ?? 'n/a',
            );
        }

        if ($this->errorMessage !== null && $this->errorMessage !== '') {
            $notes[] = sprintf('Error: %s', $this->errorMessage);
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
            'drain_strategy' => $this->drainStrategy,
            'outcome' => $this->outcome,
            'graceful' => $this->graceful,
            'duration_ms' => $this->durationMs,
            'timeout_ms' => $this->timeoutMs,
            'active_requests_before' => $this->activeRequestsBefore,
            'active_requests_after' => $this->activeRequestsAfter,
            'error_message' => $this->errorMessage,
            'valid' => $this->valid(),
            'drained_gracefully' => $this->drainedGracefully(),
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
        $outcome = (string) ($payload['outcome'] ?? self::OUTCOME_SUCCESS);
        $graceful = isset($payload['graceful']) ? (bool) $payload['graceful'] : ($outcome === self::OUTCOME_SUCCESS);

        return new self(
            driver: (string) ($payload['driver'] ?? ''),
            platform: (string) ($payload['platform'] ?? ''),
            profile: (string) ($payload['profile'] ?? 'release'),
            executedAt: isset($payload['executed_at']) && is_numeric($payload['executed_at'])
                ? (int) $payload['executed_at']
                : time(),
            drainStrategy: (string) ($payload['drain_strategy'] ?? self::STRATEGY_SIGTERM),
            outcome: $outcome,
            graceful: $graceful,
            durationMs: isset($payload['duration_ms']) && is_numeric($payload['duration_ms'])
                ? (float) $payload['duration_ms']
                : null,
            timeoutMs: isset($payload['timeout_ms']) && is_numeric($payload['timeout_ms'])
                ? (int) $payload['timeout_ms']
                : null,
            activeRequestsBefore: isset($payload['active_requests_before']) && is_numeric($payload['active_requests_before'])
                ? (int) $payload['active_requests_before']
                : null,
            activeRequestsAfter: isset($payload['active_requests_after']) && is_numeric($payload['active_requests_after'])
                ? (int) $payload['active_requests_after']
                : null,
            errorMessage: isset($payload['error_message']) && $payload['error_message'] !== null
                ? (string) $payload['error_message']
                : null,
            metadata: isset($payload['metadata']) && is_array($payload['metadata'])
                ? $payload['metadata']
                : [],
        );
    }
}
