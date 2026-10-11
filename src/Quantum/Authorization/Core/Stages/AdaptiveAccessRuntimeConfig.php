<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

/**
 * Contenedor mutable de overrides en runtime para AdaptiveAccessStage.
 *
 * Es scoped (compartido dentro de un request/command lifecycle) para que
 * el comando `authz:adaptive:tune --apply` pueda ajustar umbrales que el
 * stage lee en la siguiente evaluacion. NO persiste entre reinicios.
 *
 * Los valores null indican "sin override" (se usa el valor del constructor).
 */
final class AdaptiveAccessRuntimeConfig
{
    private ?int $stepUpThreshold = null;

    private ?int $denyThreshold = null;

    private ?int $allowThreshold = null;

    public function stepUpThreshold(): ?int
    {
        return $this->stepUpThreshold;
    }

    public function denyThreshold(): ?int
    {
        return $this->denyThreshold;
    }

    public function allowThreshold(): ?int
    {
        return $this->allowThreshold;
    }

    public function setStepUpThreshold(?int $threshold): void
    {
        $this->stepUpThreshold = $threshold;
    }

    public function setDenyThreshold(?int $threshold): void
    {
        $this->denyThreshold = $threshold;
    }

    public function setAllowThreshold(?int $threshold): void
    {
        $this->allowThreshold = $threshold;
    }

    /**
     * @return array{step_up_threshold:?int,deny_threshold:?int,allow_threshold:?int}
     */
    public function toArray(): array
    {
        return [
            'step_up_threshold' => $this->stepUpThreshold,
            'deny_threshold' => $this->denyThreshold,
            'allow_threshold' => $this->allowThreshold,
        ];
    }
}
