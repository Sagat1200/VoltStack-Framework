<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Core;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\RecoveryContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\ExceptionManagerInterface;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\RecoveryPolicyInterface;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RecoveryAction;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\SemanticError;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Throwable;

final class ExceptionManager implements ExceptionManagerInterface
{
    public function __construct(
        private readonly ExceptionNormalizerInterface $normalizer,
        private readonly SemanticExceptionMapperInterface $semanticMapper,
        private readonly ExceptionDescriptorFactory $descriptorFactory = new ExceptionDescriptorFactory(),
        private readonly ExceptionReporterPipeline $reporterPipeline = new ExceptionReporterPipeline(),
        private readonly ?RecoveryPolicyInterface $recoveryPolicy = null,
        private readonly ?TransportMapperInterface $transportMapper = null,
        private readonly ?ExceptionRendererInterface $renderer = null,
        private readonly PublicErrorProjector $publicErrorProjector = new PublicErrorProjector(),
    ) {
    }

    public function handle(Throwable $error, ExceptionContext $context): HandlingResult
    {
        $scope = $context->scope;

        if ($scope !== null) {
            $scope->enterHandling();
        }

        try {
            [$descriptor, $reportReceipt] = $this->prepare($error, $context);
            $recoveryDecision = $this->decideRecovery($descriptor, $context);
            $transportContext = $this->transportContext($context);

            if ($transportContext !== null) {
                if ($transportContext->committed) {
                    return HandlingResult::abortTransport(
                        occurrenceId: $descriptor->occurrenceId,
                        reasonCode: $descriptor->semantic->code,
                        reportReceipt: $reportReceipt,
                    );
                }

                if ($this->transportMapper !== null && $this->renderer !== null) {
                    $plan = $this->transportMapper->map($descriptor, $transportContext);
                    $publicError = $this->publicErrorProjector->project($descriptor);
                    $output = $this->renderer->render($publicError, $plan);

                    return HandlingResult::rendered(
                        occurrenceId: $descriptor->occurrenceId,
                        output: $output,
                        reportReceipt: $reportReceipt,
                    );
                }
            }

            if ($this->isJobLike($context)) {
                return HandlingResult::jobDecision(
                    occurrenceId: $descriptor->occurrenceId,
                    decision: $recoveryDecision,
                    reportReceipt: $reportReceipt,
                );
            }

            return HandlingResult::propagate(
                occurrenceId: $descriptor->occurrenceId,
                descriptor: $descriptor,
                reportReceipt: $reportReceipt,
            );
        } catch (Throwable) {
            return HandlingResult::emergency(
                occurrenceId: $context->occurrenceId ?? bin2hex(random_bytes(16)),
            );
        } finally {
            if ($scope !== null) {
                $scope->leaveHandling();
            }
        }
    }

    public function report(Throwable $error, ExceptionContext $context): ReportReceipt
    {
        [, $reportReceipt] = $this->prepare($error, $context);

        return $reportReceipt;
    }

    /**
     * @return array{0: ExceptionDescriptor, 1: ReportReceipt}
     */
    private function prepare(Throwable $error, ExceptionContext $context): array
    {
        $occurrenceId = $this->resolveOccurrenceId($error, $context);
        $failure = $this->normalizer->normalize($error, $context);
        $semantic = $this->semantic($failure, $context);
        $descriptor = $this->descriptorFactory->create($occurrenceId, $failure, $semantic, $context);
        $reportReceipt = $this->reportDescriptor($descriptor, $context);

        return [$descriptor, $reportReceipt];
    }

    private function semantic(FailureSnapshot $failure, ExceptionContext $context): SemanticError
    {
        return $this->semanticMapper->map($failure, $context)
            ?? new SemanticError(
                code: 'internal.error',
                category: \Quantum\Exceptions\Enums\SemanticCategory::Internal,
                messageKey: 'exceptions.internal.error',
                effect: $this->effectFromFailure($failure),
            );
    }

    private function reportDescriptor(ExceptionDescriptor $descriptor, ExceptionContext $context): ReportReceipt
    {
        $record = new ReportRecord(
            occurrenceId: $descriptor->occurrenceId,
            parentOccurrenceId: null,
            fingerprint: $this->fingerprint($descriptor),
            semantic: $descriptor->semantic,
            diagnostic: [
                'class' => $descriptor->failure->className,
                'origin' => $descriptor->failure->origin,
                'policy_revision' => $descriptor->policyRevision,
                'truncated' => $descriptor->failure->truncated,
            ],
            correlation: array_filter([
                'scope_id' => $context->scopeId,
                'correlation_id' => $context->correlationId,
                'transport' => is_string($context->attributes['transport_kind'] ?? null) ? $context->attributes['transport_kind'] : null,
                'trace_id' => is_string($context->attributes['trace_id'] ?? null) ? $context->attributes['trace_id'] : null,
                'tenant_id' => is_string($context->attributes['tenant_id'] ?? null) ? $context->attributes['tenant_id'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        );

        $registry = $context->scope?->registry();

        if ($registry !== null && $registry->findByOccurrenceId($descriptor->occurrenceId) === null) {
            $registry = null;
        }

        return $this->reporterPipeline->report(
            record: $record,
            budget: new ReportBudget(),
            registry: $registry,
        );
    }

    private function decideRecovery(ExceptionDescriptor $descriptor, ExceptionContext $context): \Quantum\Exceptions\Model\RecoveryDecision
    {
        $policy = $this->recoveryPolicy ?? new \Quantum\Exceptions\Recovery\DeterministicRecoveryPolicy();

        return $policy->decide($descriptor, new RecoveryContext(
            owner: is_string($context->attributes['execution_owner'] ?? null)
                ? $context->attributes['execution_owner']
                : 'request',
            effect: $descriptor->semantic->effect,
            idempotencyVerified: ($context->attributes['idempotency_verified'] ?? false) === true,
            attempt: is_int($context->attributes['attempt'] ?? null) ? $context->attributes['attempt'] : 0,
            cancelled: ($context->attributes['cancelled'] ?? false) === true,
        ));
    }

    private function transportContext(ExceptionContext $context): ?TransportContext
    {
        $kind = $context->attributes['transport_kind'] ?? $context->attributes['surface'] ?? null;

        if (! is_string($kind) || $kind === '' || $kind === 'job') {
            return null;
        }

        $accept = $context->attributes['transport_accept'] ?? [];

        return new TransportContext(
            kind: $kind,
            committed: ($context->attributes['transport_committed'] ?? false) === true,
            routeProfile: is_string($context->attributes['transport_route_profile'] ?? null)
                ? $context->attributes['transport_route_profile']
                : null,
            accept: is_array($accept) ? array_values(array_filter($accept, static fn (mixed $value): bool => is_string($value) && $value !== '')) : [],
            locale: $context->locale,
            spaVersion: is_int($context->attributes['transport_spa_version'] ?? null)
                ? $context->attributes['transport_spa_version']
                : null,
        );
    }

    private function isJobLike(ExceptionContext $context): bool
    {
        $surface = $context->attributes['surface'] ?? null;
        $owner = $context->attributes['execution_owner'] ?? null;

        return $surface === 'job' || $owner === 'job';
    }

    private function resolveOccurrenceId(Throwable $error, ExceptionContext $context): string
    {
        if ($context->scope !== null) {
            return $context->scope->registry()->identify($error, $context->occurrenceId)->occurrenceId();
        }

        $value = trim((string) $context->occurrenceId);

        return $value !== '' ? $value : bin2hex(random_bytes(16));
    }

    private function fingerprint(ExceptionDescriptor $descriptor): string
    {
        return sha1(json_encode([
            'version' => 1,
            'code' => $descriptor->semantic->code,
            'class' => $descriptor->failure->className,
            'origin' => $descriptor->failure->origin,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function effectFromFailure(FailureSnapshot $failure): Effect
    {
        return match (strtolower((string) ($failure->safeMetadata['effect'] ?? 'unknown'))) {
            'none' => Effect::None,
            'committed' => Effect::Committed,
            'partial' => Effect::Partial,
            default => Effect::Unknown,
        };
    }
}
