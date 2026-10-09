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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $semantic = $this->publicError->code;
        $limits = $this->plan->config()['limits'] ?? [];
        return [
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
                'receipts' => [],
            ],
            'limits' => [
                'configured' => is_array($limits) ? $limits : [],
                'reached' => $this->reachedLimits,
            ],
        ];
    }

    private function preview(string $body): string
    {
        if (strlen($body) <= 512) {
            return $body;
        }

        return substr($body, 0, 500) . '[TRUNCATED]';
    }
}
