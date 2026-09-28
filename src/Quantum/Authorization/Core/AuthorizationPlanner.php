<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
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
        $results = [];
        $request = $this->enrich($request);
        $contextFingerprint = $request->context()->attribute('authorization.metadata.fingerprint');
        $contextFingerprint = is_string($contextFingerprint) && trim($contextFingerprint) !== '' ? $contextFingerprint : null;

        foreach ($this->stages as $stage) {
            try {
                foreach ($stage->evaluate($request) as $result) {
                    $results[] = $this->applyFingerprint($result, $contextFingerprint);
                }
            } catch (\Throwable $exception) {
                $results[] = $this->applyFingerprint(
                    $this->stageFailure($stage->name(), $exception),
                    $contextFingerprint,
                );
            }
        }

        return $results;
    }

    public function evaluate(AuthorizationRequest $request): DecisionResult
    {
        return $this->decisions->finalize($this->plan($request));
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
