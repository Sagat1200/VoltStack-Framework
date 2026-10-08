<?php

declare(strict_types=1);

namespace Quantum\Config\Release;

use Quantum\Config\Diagnostics\ConfigStatusReport;

final readonly class ConfigReleaseCheckReport
{
    private const ALERT_NO_ACTIVE_GENERATION = 'No hay una generacion de configuracion activa.';

    private const ALERT_PUBLISHED_MISMATCH = 'El snapshot efectivo difiere de la generacion de configuracion activa.';

    public function __construct(
        private ConfigStatusReport $status,
        private bool $requireActiveGeneration = true,
        private bool $requirePublishedMatch = true,
    ) {
    }

    public function status(): ConfigStatusReport
    {
        return $this->status;
    }

    public function requireActiveGeneration(): bool
    {
        return $this->requireActiveGeneration;
    }

    public function requirePublishedMatch(): bool
    {
        return $this->requirePublishedMatch;
    }

    public function passed(): bool
    {
        return $this->violations() === [];
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->status->alerts() as $alert) {
            if ($alert === self::ALERT_NO_ACTIVE_GENERATION && ! $this->requireActiveGeneration) {
                continue;
            }

            if ($alert === self::ALERT_PUBLISHED_MISMATCH && ! $this->requirePublishedMatch) {
                continue;
            }

            $violations[] = $alert;
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'require_active_generation' => $this->requireActiveGeneration,
            'require_published_match' => $this->requirePublishedMatch,
            'violations' => $this->violations(),
            'status' => $this->status->toArray(),
        ];
    }
}
