<?php

declare(strict_types=1);

namespace Quantum\Authorization\Exceptions;

use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Throwable;

final class AuthorizationExceptionMapper implements ExceptionMapperInterface
{
    public function statusCode(Throwable $throwable): ?int
    {
        return match (true) {
            $throwable instanceof AuthorizationChallengeException => 401,
            $throwable instanceof AuthorizationDeniedException => 403,
            $throwable instanceof AuthorizationEvaluationException => 500,
            default => null,
        };
    }

    public function headers(Throwable $throwable): array
    {
        if (! $throwable instanceof AuthorizationException) {
            return [];
        }

        $metadata = $throwable->result()->metadata();
        $headers = [];

        if ($throwable instanceof AuthorizationChallengeException) {
            $headers['WWW-Authenticate'] = 'Session realm="VoltStack", error="authorization_required"';

            if ($throwable->result()->reasonCode() === 'auth.step_up_required' || ($metadata['adaptive_access_action'] ?? null) === 'step_up') {
                $headers['X-Auth-Step-Up'] = 'required';
                $headers['X-Auth-Risk-Score'] = isset($metadata['risk_score']) ? (string) $metadata['risk_score'] : null;
                $headers['X-Auth-Risk-Level'] = is_string($metadata['risk_level'] ?? null) ? $metadata['risk_level'] : null;
                $headers['X-Auth-Risk-Step-Up-Threshold'] = isset($metadata['risk_step_up_threshold']) ? (string) $metadata['risk_step_up_threshold'] : null;
                $headers['X-Auth-Step-Up-Challenge-Endpoint'] = is_string($metadata['step_up_challenge_endpoint'] ?? null) ? $metadata['step_up_challenge_endpoint'] : null;
                $headers['X-Auth-Step-Up-Continuation-Endpoint'] = is_string($metadata['step_up_continuation_endpoint'] ?? null) ? $metadata['step_up_continuation_endpoint'] : null;
                $methods = $metadata['step_up_available_methods'] ?? [];
                $headers['X-Auth-Step-Up-Available-Methods'] = is_array($methods) && $methods !== [] ? implode(',', array_map('strval', $methods)) : null;
            }
        }

        if ($throwable instanceof AuthorizationDeniedException) {
            if ($throwable->result()->reasonCode() === 'auth.risk_denied' || ($metadata['adaptive_access_action'] ?? null) === 'deny') {
                $headers['X-Auth-Risk-Denied'] = 'true';
                $headers['X-Auth-Risk-Score'] = isset($metadata['risk_score']) ? (string) $metadata['risk_score'] : null;
                $headers['X-Auth-Risk-Level'] = is_string($metadata['risk_level'] ?? null) ? $metadata['risk_level'] : null;
                $headers['X-Auth-Risk-Deny-Threshold'] = isset($metadata['risk_deny_threshold']) ? (string) $metadata['risk_deny_threshold'] : null;
            }
        }

        return array_filter($headers, static fn (mixed $value): bool => $value !== null);
    }

    public function jsonExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->extensions($throwable, $debug);
    }

    public function voltExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->extensions($throwable, $debug);
    }

    public function message(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof AuthorizationChallengeException,
            $throwable instanceof AuthorizationDeniedException => $throwable->getMessage(),
            $throwable instanceof AuthorizationEvaluationException => 'Server Error',
            default => null,
        };
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof AuthorizationChallengeException,
            $throwable instanceof AuthorizationDeniedException => $throwable->result()->reasonCode(),
            default => null,
        };
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof AuthorizationChallengeException => '<p>Authorization requires an additional challenge before this resource can be accessed.</p>',
            $throwable instanceof AuthorizationDeniedException => '<p>You do not have permission to perform this authorization-protected action.</p>',
            $throwable instanceof AuthorizationEvaluationException => '<p>An unexpected error occurred while evaluating authorization rules.</p>',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extensions(Throwable $throwable, bool $debug): array
    {
        if (! $throwable instanceof AuthorizationException) {
            return [];
        }

        $extensions = [
            'reason_code' => $throwable->result()->reasonCode(),
            'source' => $throwable->result()->source(),
        ];

        $metadata = $throwable->result()->metadata();

        foreach ([
            'risk_score',
            'risk_level',
            'risk_step_up_threshold',
            'risk_deny_threshold',
            'step_up_challenge_endpoint',
            'step_up_continuation_endpoint',
            'required_strength_name',
            'required_strength_value',
        ] as $key) {
            if (array_key_exists($key, $metadata)) {
                $extensions[$key] = $metadata[$key];
            }
        }

        if (is_array($metadata['step_up_available_methods'] ?? null) && $metadata['step_up_available_methods'] !== []) {
            $extensions['step_up_available_methods'] = $metadata['step_up_available_methods'];
        }

        if ($debug) {
            $extensions['metadata'] = $metadata;
        }

        return $extensions;
    }
}
