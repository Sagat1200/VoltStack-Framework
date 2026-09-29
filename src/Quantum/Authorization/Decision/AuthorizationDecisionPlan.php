<?php

declare(strict_types=1);

namespace Quantum\Authorization\Decision;

/**
 * Value Object que representa un plan de evaluación de autorización completo,
 * con traza por stage y un resultado final agregado por DecisionManager.
 *
 * Permite explainability estructurado y serializable para auditoría,
 * compliance y depuración operativa sin alterar el runtime.
 */
final readonly class AuthorizationDecisionPlan
{
    /**
     * @param list<array{name: string, results: list<DecisionResult>}> $stages
     */
    public function __construct(
        private ?string $fingerprint,
        private array $stages,
        private DecisionResult $final,
        private int $evaluatedAt,
    ) {}

    /**
     * Estructura plana y exportable del plan, apta para JSON, audit logs
     * y middleware de observabilidad.
     *
     * Forma:
     *  {
     *    fingerprint: string|null,
     *    stages: [{name, results: [{decision, source, reason_code, metadata, metadata_fingerprint}]}],
     *    final: {decision, source, reason_code, metadata, metadata_fingerprint},
     *    evaluated_at: int
     *  }
     *
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'stages'    => array_map(
                function (array $stage): array {
                    return [
                        'name'    => $stage['name'],
                        'results' => array_map(
                            fn (DecisionResult $r): array => $this->serializeDecisionResult($r),
                            $stage['results'] ?? [],
                        ),
                    ];
                },
                $this->stages,
            ),
            'final'      => $this->serializeDecisionResult($this->final),
            'evaluated_at' => $this->evaluatedAt,
        ];
    }

    /**
     * Resultado final agregado por DecisionManager (idéntico al que
     * devuelve AuthorizationPlanner::evaluate).
     */
    public function finalResult(): DecisionResult
    {
        return $this->final;
    }

    /**
     * Stages crudos tal cual fueron evaluados por el planner, con objetos
     * DecisionResult sin serializar (útil para tests / introspección).
     *
     * @return list<array{name: string, results: list<DecisionResult>}>
     */
    public function stagesRaw(): array
    {
        return $this->stages;
    }

    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function evaluatedAt(): int
    {
        return $this->evaluatedAt;
    }

    /**
     * @return array{decision: string, source: string, reason_code: string, metadata: array<string, mixed>, metadata_fingerprint: string|null}
     */
    private function serializeDecisionResult(DecisionResult $result): array
    {
        return [
            'decision'            => $result->decision()->value,
            'source'              => $result->source(),
            'reason_code'         => $result->reasonCode(),
            'metadata'            => $result->metadata(),
            'metadata_fingerprint' => $result->metadataFingerprint(),
        ];
    }
}
