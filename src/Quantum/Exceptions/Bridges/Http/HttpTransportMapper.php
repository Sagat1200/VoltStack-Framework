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

    public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan
    {
        if ($context->kind !== 'http') {
            throw new \InvalidArgumentException('HttpTransportMapper only supports the http transport kind.');
        }

        $status = $this->statusFor($descriptor);
        $target = $this->targetFor($context);
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

        return new TransportPlan(
            target: $target,
            status: $status,
            headers: $headers,
            retryAfterSeconds: $retryAfter,
        );
    }

    private function targetFor(TransportContext $context): string
    {
        $profile = strtolower(trim((string) $context->routeProfile));

        if (in_array($profile, ['api', 'json'], true)) {
            return 'http.json';
        }

        if (in_array($profile, ['html', 'web', 'browser'], true)) {
            return 'http.html';
        }

        $negotiated = $this->negotiateAccept($context->accept);

        if ($negotiated !== null) {
            return $negotiated;
        }

        return 'http.html';
    }

    /**
     * @param list<string> $values
     */
    private function negotiateAccept(array $values): ?string
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

            foreach (['http.json', 'http.html'] as $candidate) {
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
            'http.json' => $this->jsonSpecificity($mediaType),
            'http.html' => $this->htmlSpecificity($mediaType),
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
}
