<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\TransportPlan;

final readonly class ExceptionExplainReport
{
    /**
     * @param array<string, mixed>|null $matchedRule
     * @param array<string, scalar|array|null> $safeParameters
     * @param array<string, mixed> $fixture
     * @param list<string> $reporterIds
     * @param list<string> $reachedLimits
     * @param list<array<string, mixed>> $reporterReceipts
     * @param array<string, array{accepted:int,dropped:int,failed:int,skipped:int,total:int}> $reporterReceiptsMatrix
     * @param array<string, mixed>|null $reportingDecision
     * @param array<string, mixed>|null $recoveryDecision
     * @param array<string, mixed>|null $transportDiagnostic
     * @param list<array<string, mixed>> $bridgeMatrix
     * @param list<array<string, mixed>> $effectMatrix
     * @param array<string, list<array<string, mixed>>> $receiptsMatrixByScope
     * @param array<string, mixed>|null $runtimeDiagnostic
     * @param array<string, mixed>|null $streamConfirmation
     */
    public function __construct(
        private ExceptionCompilationPlan $plan,
        private array $fixture,
        private FailureSnapshot $failure,
        private ?array $matchedRule,
        private bool $usedFallback,
        private array $safeParameters,
        private PublicError $publicError,
        private TransportPlan $transportPlan,
        private RenderedOutput $rendered,
        private array $reporterIds,
        private bool $ignoredByPolicy,
        private array $reachedLimits,
        private array $reporterReceipts = [],
        private array $reporterReceiptsMatrix = [],
        private ?array $reportingDecision = null,
        private ?array $recoveryDecision = null,
        private ?array $transportDiagnostic = null,
        private array $bridgeMatrix = [],
        private array $effectMatrix = [],
        private array $receiptsMatrixByScope = [],
        private ?array $runtimeDiagnostic = null,
        private ?array $streamConfirmation = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $semantic = $this->publicError->code;
        $limits = $this->plan->config()['limits'] ?? [];
        $result = [
            'effective' => [
                'environment' => $this->plan->environment(),
                'runtime' => $this->plan->runtime(),
                'debug' => $this->plan->debug(),
                'fingerprint' => $this->plan->fingerprint(),
                'policy_revision' => $this->plan->policyRevision(),
                'php_runtime_version' => $this->plan->phpRuntimeVersion(),
                'reporters' => $this->reporterIds,
                'spa_versions' => $this->plan->spaVersions(),
            ],
            'fixture' => $this->fixture,
            'mapping' => [
                'code' => $semantic,
                'matched_rule' => $this->matchedRule,
                'used_fallback' => $this->usedFallback,
                'safe_parameters' => $this->safeParameters,
            ],
            'public_error' => [
                'code' => $this->publicError->code,
                'message' => $this->publicError->message,
                'occurrence_id' => $this->publicError->occurrenceId,
                'fields' => $this->publicError->fields,
            ],
            'transport_plan' => [
                'target' => $this->transportPlan->target,
                'status' => $this->transportPlan->status,
                'exit_code' => $this->transportPlan->exitCode,
                'headers' => $this->transportPlan->headers,
                'spa_action' => $this->transportPlan->spaAction,
                'retry_after_seconds' => $this->transportPlan->retryAfterSeconds,
                'metadata' => $this->transportPlan->metadata,
            ],
            'rendered' => [
                'target' => $this->rendered->target,
                'media_type' => $this->rendered->mediaType,
                'status' => $this->rendered->status,
                'exit_code' => $this->rendered->exitCode,
                'safe_headers' => $this->rendered->safeHeaders,
                'body_length' => strlen($this->rendered->bodyBytes),
                'body_preview' => $this->preview($this->rendered->bodyBytes),
            ],
            'failure' => [
                'class_name' => $this->failure->className,
                'internal_message' => $this->failure->internalMessage,
                'origin' => $this->failure->origin,
                'frames' => $this->failure->frames,
                'causes' => $this->failure->causes,
                'safe_metadata' => $this->failure->safeMetadata,
                'truncated' => $this->failure->truncated,
            ],
            'reporting' => [
                'simulated' => true,
                'reporter_ids' => $this->reporterIds,
                'ignored_by_policy' => $this->ignoredByPolicy,
                'decision' => $this->reportingDecision,
                'receipts' => $this->reporterReceipts,
                'receipts_matrix' => $this->reporterReceiptsMatrix,
                'stream_confirmation' => $this->streamConfirmation,
            ],
            'recovery' => [
                'simulated' => true,
                'decision' => $this->recoveryDecision,
            ],
            'transport' => $this->transportDiagnostic ?? [
                'bridge' => 'unavailable',
                'kind' => null,
                'route_profile' => null,
            ],
            'limits' => [
                'configured' => is_array($limits) ? $limits : [],
                'reached' => $this->reachedLimits,
            ],
        ];
        if ($this->bridgeMatrix !== []) {
            $result['bridge_matrix'] = $this->bridgeMatrix;
        }
        if ($this->effectMatrix !== []) {
            $result['effect_matrix'] = $this->effectMatrix;
        }
        if ($this->receiptsMatrixByScope !== []) {
            $result['receipts_matrix_by_scope'] = $this->receiptsMatrixByScope;
        }
        if ($this->runtimeDiagnostic !== null) {
            $result['runtime'] = $this->runtimeDiagnostic;
        }

        return $result;
    }

    private function preview(string $body): string
    {
        if (strlen($body) <= 512) {
            return $body;
        }

        return substr($body, 0, 500) . '[TRUNCATED]';
    }
}
