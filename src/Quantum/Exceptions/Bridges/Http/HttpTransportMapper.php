<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Http;

use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\TransportPlan;

final class HttpTransportMapper implements TransportMapperInterface
{
    private const MAX_ACCEPT_LENGTH = 4096;
    private const MAX_ACCEPT_VALUES = 32;
    private const SPA_MEDIA_TYPE = 'application/vnd.voltstack.spa-error+json';
    private const SPA_VERSION = 1;

    public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan
    {
        if ($context->kind !== 'http') {
            throw new \InvalidArgumentException('HttpTransportMapper only supports the http transport kind.');
        }

        $headers = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($context->locale !== null && $context->locale !== '') {
            $headers['Content-Language'] = $context->locale;
        }

        $retryAfter = $this->retryAfterFor($descriptor);

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        $resolved = $this->resolveTarget($descriptor, $context);

        return new TransportPlan(
            target: $resolved['target'],
            status: $resolved['status'] ?? $this->statusFor($descriptor),
            headers: $headers,
            spaAction: is_string($resolved['spa_action'] ?? null) ? $resolved['spa_action'] : null,
            retryAfterSeconds: $retryAfter,
            metadata: is_array($resolved['metadata'] ?? null) ? $resolved['metadata'] : [],
        );
    }

    /**
     * @return array{
     *   target: string,
     *   status?: int,
     *   spa_action?: string,
     *   metadata?: array<string, scalar|array|null>
     * }
     */
    private function resolveTarget(ExceptionDescriptor $descriptor, TransportContext $context): array
    {
        $profile = strtolower(trim((string) $context->routeProfile));

        if (in_array($profile, ['spa', 'spa_v1'], true)) {
            return $this->resolveSpaTarget($descriptor, $context);
        }

        if (in_array($profile, ['api', 'problem', 'problem_json'], true)) {
            return ['target' => 'http.problem_json'];
        }

        if (in_array($profile, ['json', 'legacy_json'], true)) {
            return ['target' => 'http.json'];
        }

        if (in_array($profile, ['html', 'web', 'browser'], true)) {
            return ['target' => 'http.html'];
        }

        $negotiated = $this->negotiateAccept($context->accept, false);

        if ($negotiated !== null) {
            return ['target' => $negotiated];
        }

        return ['target' => 'http.html'];
    }

    /**
     * @param list<string> $values
     */
    private function negotiateAccept(array $values, bool $allowSpa): ?string
    {
        $raw = trim(implode(',', array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')));

        if ($raw === '' || strlen($raw) > self::MAX_ACCEPT_LENGTH) {
            return null;
        }

        $parts = array_map('trim', explode(',', $raw));

        if (count($parts) > self::MAX_ACCEPT_VALUES) {
            return null;
        }

        $best = null;
        $bestQ = -1.0;
        $bestSpecificity = -1;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            [$mediaType, $q] = $this->parseAcceptPart($part);

            if ($q <= 0.0) {
                continue;
            }

            $candidates = $allowSpa
                ? ['spa.error.v1', 'http.problem_json', 'http.json', 'http.html']
                : ['http.problem_json', 'http.json', 'http.html'];

            foreach ($candidates as $candidate) {
                $specificity = $this->specificityFor($mediaType, $candidate);

                if ($specificity < 0) {
                    continue;
                }

                if ($q > $bestQ || ($q === $bestQ && $specificity > $bestSpecificity)) {
                    $best = $candidate;
                    $bestQ = $q;
                    $bestSpecificity = $specificity;
                }
            }
        }

        return $best;
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function parseAcceptPart(string $part): array
    {
        $segments = array_map('trim', explode(';', $part));
        $mediaType = strtolower((string) array_shift($segments));
        $q = 1.0;

        foreach ($segments as $segment) {
            if (! str_starts_with($segment, 'q=')) {
                continue;
            }

            $parsed = (float) substr($segment, 2);
            $q = max(0.0, min(1.0, $parsed));
            break;
        }

        return [$mediaType, $q];
    }

    private function specificityFor(string $mediaType, string $candidate): int
    {
        return match ($candidate) {
            'spa.error.v1' => $this->spaSpecificity($mediaType),
            'http.problem_json' => $this->problemJsonSpecificity($mediaType),
            'http.json' => $this->jsonSpecificity($mediaType),
            'http.html' => $this->htmlSpecificity($mediaType),
            default => -1,
        };
    }

    private function spaSpecificity(string $mediaType): int
    {
        return match (true) {
            $mediaType === self::SPA_MEDIA_TYPE => 6,
            str_starts_with($mediaType, self::SPA_MEDIA_TYPE . ';') => 5,
            default => -1,
        };
    }

    private function problemJsonSpecificity(string $mediaType): int
    {
        return match ($mediaType) {
            'application/problem+json' => 5,
            'application/*' => 2,
            '*/*' => 1,
            default => -1,
        };
    }

    private function jsonSpecificity(string $mediaType): int
    {
        return match (true) {
            $mediaType === 'application/json' => 4,
            str_ends_with($mediaType, '+json') => 3,
            $mediaType === 'application/*' => 2,
            $mediaType === '*/*' => 1,
            default => -1,
        };
    }

    private function htmlSpecificity(string $mediaType): int
    {
        return match ($mediaType) {
            'text/html' => 4,
            'text/*' => 2,
            '*/*' => 1,
            default => -1,
        };
    }

    private function statusFor(ExceptionDescriptor $descriptor): int
    {
        return match ($descriptor->semantic->code) {
            'validation.failed' => 422,
            'authentication.required', 'authentication.failed' => 401,
            'authorization.denied', 'authorization.challenge' => 403,
            'resource.not_found' => 404,
            'resource.conflict' => 409,
            'request.throttled' => 429,
            'dependency.unavailable', 'operation.indeterminate', 'operation.cancelled' => 503,
            'configuration.invalid', 'internal.error' => 500,
            default => 500,
        };
    }

    private function retryAfterFor(ExceptionDescriptor $descriptor): ?int
    {
        $value = $descriptor->semantic->safeParameters['retry_after'] ?? null;

        if (! is_int($value) || $value < 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return array{
     *   target: string,
     *   status?: int,
     *   spa_action?: string,
     *   metadata?: array<string, scalar|array|null>
     * }
     */
    private function resolveSpaTarget(ExceptionDescriptor $descriptor, TransportContext $context): array
    {
        if ($context->spaVersion !== self::SPA_VERSION || ! $this->supportsSpaAccept($context->accept)) {
            return [
                'target' => 'http.problem_json',
                'status' => 406,
                'metadata' => [
                    'problem_code' => 'spa.protocol_unsupported',
                    'problem_title' => 'Not Acceptable',
                    'problem_detail' => 'The requested SPA protocol version is not supported.',
                    'problem_status' => 406,
                    'problem_extensions' => [
                        'supported_versions' => [self::SPA_VERSION],
                    ],
                ],
            ];
        }

        $targetScope = $this->targetScopeFor($descriptor);
        $action = $this->spaActionFor($descriptor, $targetScope);

        return [
            'target' => 'spa.error.v1',
            'status' => $this->statusFor($descriptor),
            'spa_action' => $action,
            'metadata' => [
                'spa_version' => self::SPA_VERSION,
                'request_id' => $this->stringOrNull($descriptor->contextSummary['request_id'] ?? null),
                'operation_id' => $this->stringOrNull($descriptor->contextSummary['operation_id'] ?? null),
                'navigation_id' => $this->stringOrNull($descriptor->contextSummary['navigation_id'] ?? null),
                'target_scope' => $targetScope,
                'target_id' => $targetScope === 'component'
                    ? $this->stringOrNull($descriptor->contextSummary['spa_target_id'] ?? null)
                    : null,
                'target_revision' => $targetScope === 'component' && is_int($descriptor->contextSummary['spa_target_revision'] ?? null)
                    ? $descriptor->contextSummary['spa_target_revision']
                    : null,
                'effect' => $descriptor->semantic->effect->name,
                'retry' => $this->spaRetryFor($descriptor),
                'reconcile' => $this->spaReconcileFor($descriptor),
            ],
        ];
    }

    /**
     * @param list<string> $accept
     */
    private function supportsSpaAccept(array $accept): bool
    {
        foreach ($accept as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = strtolower(trim($value));

            if ($normalized === self::SPA_MEDIA_TYPE || str_starts_with($normalized, self::SPA_MEDIA_TYPE . ';')) {
                return true;
            }
        }

        return false;
    }

    private function targetScopeFor(ExceptionDescriptor $descriptor): string
    {
        $scope = strtolower(trim((string) ($descriptor->contextSummary['spa_target_scope'] ?? '')));

        return $scope === 'component' ? 'component' : 'page';
    }

    private function spaActionFor(ExceptionDescriptor $descriptor, string $targetScope): string
    {
        return match ($descriptor->semantic->code) {
            'validation.failed' => 'show_fields',
            'authentication.required', 'authentication.failed' => 'authenticate',
            'authorization.challenge' => 'challenge',
            'operation.indeterminate', 'operation.cancelled' => 'reconcile',
            'dependency.unavailable' => 'retry_read',
            default => $targetScope === 'component' ? 'show_boundary' : 'show_page',
        };
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function spaRetryFor(ExceptionDescriptor $descriptor): array
    {
        $allowed = $descriptor->semantic->retryAdvice !== \Quantum\Exceptions\Enums\RetryAdvice::Never
            && ($descriptor->contextSummary['idempotency_verified'] ?? false) === true;

        $payload = [
            'allowed' => $allowed,
        ];

        if (! $allowed) {
            return $payload;
        }

        if (($retryAfter = $this->retryAfterFor($descriptor)) !== null) {
            $payload['after_ms'] = $retryAfter * 1000;
        }

        $payload['max_attempts'] = 1;

        return $payload;
    }

    /**
     * @return array<string, scalar>|null
     */
    private function spaReconcileFor(ExceptionDescriptor $descriptor): ?array
    {
        if (! in_array($descriptor->semantic->code, ['operation.indeterminate', 'operation.cancelled'], true)) {
            return null;
        }

        $payload = [];

        if (($operationRef = $this->stringOrNull($descriptor->contextSummary['spa_reconcile_operation_ref'] ?? null)) !== null) {
            $payload['operation_ref'] = $operationRef;
        }

        if (($routeKey = $this->stringOrNull($descriptor->contextSummary['spa_reconcile_route_key'] ?? null)) !== null) {
            $payload['route_key'] = $routeKey;
        }

        return $payload === [] ? null : $payload;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
