<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Spa;

use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\TransportPlan;

final class SpaExceptionRenderer
{
    private const MEDIA_TYPE = 'application/vnd.voltstack.spa-error+json;v=1';
    private const MAX_FIELDS = 100;

    public function render(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        $status = $plan->status ?? 500;
        $targetScope = $this->stringValue($plan->metadata['target_scope'] ?? null) ?? 'page';
        $payload = [
            'protocol' => 'voltstack.spa.error',
            'version' => 1,
            'kind' => 'exception',
            'occurrence_id' => $error->occurrenceId,
            'status' => $status,
            'error' => [
                'code' => $error->code,
                'message' => $error->message,
            ],
            'target' => $this->targetPayload($plan, $targetScope),
            'action' => $plan->spaAction ?? ($targetScope === 'component' ? 'show_boundary' : 'show_page'),
            'effect' => $this->stringValue($plan->metadata['effect'] ?? null) ?? 'Unknown',
            'retry' => $this->retryPayload($plan),
            'reconcile' => $this->reconcilePayload($plan),
        ];

        if (($requestId = $this->stringValue($plan->metadata['request_id'] ?? null)) !== null) {
            $payload['request_id'] = $requestId;
        }

        if (($operationId = $this->stringValue($plan->metadata['operation_id'] ?? null)) !== null) {
            $payload['operation_id'] = $operationId;
        }

        if (($navigationId = $this->stringValue($plan->metadata['navigation_id'] ?? null)) !== null) {
            $payload['navigation_id'] = $navigationId;
        }

        $fields = $this->fieldsPayload($error);

        if ($fields !== []) {
            $payload['error']['fields'] = $fields;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($encoded)) {
            throw new \RuntimeException('SpaExceptionRenderer failed to encode the SPA error envelope.');
        }

        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $encoded,
            mediaType: self::MEDIA_TYPE,
            status: $status,
            safeHeaders: $plan->headers,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function targetPayload(TransportPlan $plan, string $targetScope): array
    {
        $payload = [
            'scope' => $targetScope,
        ];

        if ($targetScope !== 'component') {
            return $payload;
        }

        $payload['id'] = $this->stringValue($plan->metadata['target_id'] ?? null);
        $payload['revision'] = is_int($plan->metadata['target_revision'] ?? null)
            ? $plan->metadata['target_revision']
            : 0;

        return $payload;
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function retryPayload(TransportPlan $plan): array
    {
        $retry = $plan->metadata['retry'] ?? ['allowed' => false];

        if (! is_array($retry)) {
            return ['allowed' => false];
        }

        return $retry;
    }

    /**
     * @return array<string, scalar>|null
     */
    private function reconcilePayload(TransportPlan $plan): ?array
    {
        $reconcile = $plan->metadata['reconcile'] ?? null;

        if (! is_array($reconcile) || $reconcile === []) {
            return null;
        }

        return $reconcile;
    }

    /**
     * @return list<array<string, string>>
     */
    private function fieldsPayload(PublicError $error): array
    {
        if ($error->fields === null) {
            return [];
        }

        $result = [];

        foreach (array_slice($error->fields, 0, self::MAX_FIELDS) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $result[] = [
                'path' => $this->pointerFor($field['path'] ?? null),
                'code' => is_string($field['code'] ?? null) && $field['code'] !== ''
                    ? $field['code']
                    : 'validation.invalid',
                'message' => 'Invalid value.',
            ];
        }

        return $result;
    }

    private function pointerFor(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '/';
        }

        return str_starts_with($value, '/') ? $value : '/' . ltrim($value, '/');
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
