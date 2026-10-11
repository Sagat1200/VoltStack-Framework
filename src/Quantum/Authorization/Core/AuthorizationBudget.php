<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

/**
 * Presupuesto de recursos para el pipeline de autorizacion.
 *
 * Todos los limites son opcionales. Un valor null significa "sin limite"
 * para ese campo. El pipeline evalua el presupuesto antes de cada stage.
 */
final readonly class AuthorizationBudget
{
    public function __construct(
        public ?int $maxDurationMs = null,
        public ?int $maxStages = null,
        public ?int $maxDecisionCost = null,
    ) {}

    /**
     * Determina si el presupuesto fue excedido.
     *
     * @return string|null El campo que excedio el presupuesto, o null si ok.
     */
    public function exceeded(int $durationMs, int $stagesExecuted, int $cost): ?string
    {
        if ($this->maxDurationMs !== null && $durationMs > $this->maxDurationMs) {
            return 'max_duration_ms';
        }

        if ($this->maxStages !== null && $stagesExecuted > $this->maxStages) {
            return 'max_stages';
        }

        if ($this->maxDecisionCost !== null && $cost > $this->maxDecisionCost) {
            return 'max_decision_cost';
        }

        return null;
    }
}
