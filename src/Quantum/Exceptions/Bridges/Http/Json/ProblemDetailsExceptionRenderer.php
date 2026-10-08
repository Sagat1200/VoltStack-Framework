<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Http\Json;

use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\TransportPlan;

final class ProblemDetailsExceptionRenderer
{
    private const DEFAULT_MAX_ERRORS = 100;
    private const DEFAULT_MAX_BYTES = 32768;

    public function __construct(
        private readonly ?string $typeBaseUri = null,
        private readonly int $maxErrors = self::DEFAULT_MAX_ERRORS,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    public function render(PublicError $error, TransportPlan $plan): RenderedOutput
    {
        $status = $plan->status ?? 500;
        $payload = [
            'type' => $this->typeFor($error->code),
            'title' => $this->titleFor($status),
            'status' => $status,
            'detail' => $error->message,
            'instance' => sprintf('urn:voltstack:occurrence:%s', $error->occurrenceId),
            'code' => $error->code,
        ];

        $errors = $this->errorsFor($error);

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (! is_string($encoded) || strlen($encoded) > $this->maxBytes) {
            $encoded = $this->fallbackPayload($status);
        }

        return new RenderedOutput(
            target: $plan->target,
            bodyBytes: $encoded,
            mediaType: 'application/problem+json',
            status: $status,
            safeHeaders: $plan->headers,
        );
    }

    private function typeFor(string $code): string
    {
        $baseUri = trim((string) $this->typeBaseUri);

        if ($baseUri === '') {
            return 'about:blank';
        }

        return rtrim($baseUri, '/') . '/' . str_replace('.', '-', $code);
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            412 => 'Precondition Failed',
            413 => 'Payload Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Application Error',
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function errorsFor(PublicError $error): array
    {
        if ($error->fields === null) {
            return [];
        }

        $result = [];

        foreach (array_slice($error->fields, 0, $this->maxErrors) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $pointer = $this->pointerFor($field['path'] ?? null);
            $code = is_string($field['code'] ?? null) && $field['code'] !== ''
                ? $field['code']
                : 'validation.invalid';

            $result[] = [
                'pointer' => $pointer,
                'code' => $code,
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

    private function fallbackPayload(int $status): string
    {
        return (string) json_encode([
            'type' => 'about:blank',
            'title' => $this->titleFor($status >= 400 && $status <= 599 ? $status : 500),
            'status' => $status >= 400 && $status <= 599 ? $status : 500,
            'detail' => 'An internal error occurred.',
            'code' => 'internal.error',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
