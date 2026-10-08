<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Core;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;

final readonly class ExceptionDescriptorFactory
{
    public function __construct(
        private string $policyRevision = 'v1',
    ) {
    }

    public function create(
        string $occurrenceId,
        FailureSnapshot $failure,
        SemanticError $semantic,
        ExceptionContext $context,
    ): ExceptionDescriptor {
        return new ExceptionDescriptor(
            occurrenceId: $occurrenceId,
            contextSummary: $this->contextSummary($context),
            failure: $failure,
            semantic: $semantic,
            policyRevision: $this->policyRevision,
        );
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function contextSummary(ExceptionContext $context): array
    {
        $summary = [
            'scope_id' => $context->scopeId,
            'correlation_id' => $context->correlationId,
            'locale' => $context->locale,
            'debug' => $context->debug,
        ];

        foreach ([
            'surface',
            'origin',
            'transport_kind',
            'transport_route_profile',
            'execution_owner',
            'request_id',
            'operation_id',
            'navigation_id',
            'spa_target_scope',
            'spa_target_id',
            'spa_target_revision',
            'spa_reconcile_operation_ref',
            'spa_reconcile_route_key',
            'idempotency_verified',
        ] as $key) {
            if (array_key_exists($key, $context->attributes)) {
                $summary[$key] = $context->attributes[$key];
            }
        }

        return array_filter($summary, static fn (mixed $value): bool => $value !== null);
    }
}
