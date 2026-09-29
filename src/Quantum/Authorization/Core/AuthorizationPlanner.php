<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Decision\AuthorizationDecisionPlan;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Decision\DecisionResult;

final class AuthorizationPlanner implements AuthorizationPlannerInterface
{
    /**
     * @param list<AuthorizationRequestEnricherInterface> $enrichers
     * @param list<AuthorizationEvaluationStageInterface> $stages
     */
    public function __construct(
        private readonly array $enrichers,
        private readonly array $stages,
        private readonly DecisionManager $decisions,
        private readonly bool $failClosed = true,
    ) {}

    public function plan(AuthorizationRequest $request): array
    {
        [$results, , ] = $this->collectStages($request);

        return $results;
    }

    public function evaluate(AuthorizationRequest $request): DecisionResult
    {
        return $this->decisions->finalize($this->plan($request));
    }

    /**
     * Ejecuta el mismo pipeline que plan() + evaluate(), pero devuelve
     * un AuthorizationDecisionPlan completo con trazabilidad por stage
     * y decision final agregada para explainability y auditoria.
     */
    public function planAsDecisionPlan(AuthorizationRequest $request): AuthorizationDecisionPlan
    {
        [$results, $stagesTrace, $fingerprint] = $this->collectStages($request);
        $final = $this->decisions->finalize($results);

        return new AuthorizationDecisionPlan(
            fingerprint: $fingerprint,
            stages: $stagesTrace,
            final: $final,
            evaluatedAt: time(),
        );
    }

    /**
     * Core loop del planner: apply enrichers, iterar stages, aplicar fingerprint
     * a cada DecisionResult y recolectar tanto la lista plana de resultados como
     * la estructura agrupada por stage para el explain plan.
     *
     * @return array{0: list<DecisionResult>, 1: list<array{name: string, results: list<DecisionResult>}>, 2: string|null}
     */
    private function collectStages(AuthorizationRequest $request): array
    {
        $results = [];
        $stagesTrace = [];
        $request = $this->enrich($request);
        $contextFingerprint = $request->context()->attribute('authorization.metadata.fingerprint');
        $contextFingerprint = is_string($contextFingerprint) && trim($contextFingerprint) !== '' ? $contextFingerprint : null;

        foreach ($this->stages as $stage) {
            $stageName = $stage->name();
            $stageResults = [];

            try {
                foreach ($stage->evaluate($request) as $result) {
                    $fingerprinted = $this->applyFingerprint($result, $contextFingerprint);
                    $results[] = $fingerprinted;
                    $stageResults[] = $fingerprinted;
                }
            } catch (\Throwable $exception) {
                $fingerprinted = $this->applyFingerprint(
                    $this->stageFailure($stageName, $exception),
                    $contextFingerprint,
                );
                $results[] = $fingerprinted;
                $stageResults[] = $fingerprinted;
            }

            $stagesTrace[] = ['name' => $stageName, 'results' => $stageResults];
        }

        return [$results, $stagesTrace, $contextFingerprint];
    }

    private function enrich(AuthorizationRequest $request): AuthorizationRequest
    {
        foreach ($this->enrichers as $enricher) {
            $request = $enricher->enrich($request);
        }

        return $request;
    }

    private function stageFailure(string $stage, \Throwable $exception): DecisionResult
    {
        if ($this->failClosed) {
            return DecisionResult::failure(
                source: 'authorization.stage:' . $stage,
                reasonCode: 'authorization_evaluation_failed_fail_closed',
                metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
            );
        }

        return DecisionResult::abstain(
            source: 'authorization.stage:' . $stage,
            reasonCode: 'authorization_evaluation_failed_fail_open',
            metadata: ['exception' => $exception::class, 'message' => $exception->getMessage()],
        );
    }

    private function applyFingerprint(DecisionResult $result, ?string $fingerprint): DecisionResult
    {
        if ($fingerprint === null || $result->metadataFingerprint() !== null) {
            return $result;
        }

        $reflection = new \ReflectionClass(DecisionResult::class);
        $newInstance = $reflection->newInstanceWithoutConstructor();

        foreach (['decision', 'source', 'reasonCode', 'metadata', 'metadataFingerprint'] as $propName) {
            $prop = $reflection->getProperty($propName);
            $prop->setAccessible(true);

            if ($propName === 'metadataFingerprint') {
                $prop->setValue($newInstance, $fingerprint);
                continue;
            }

            $prop->setValue($newInstance, $prop->getValue($result));
        }

        return $newInstance;
    }
}
