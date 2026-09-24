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
        return match (true) {
            $throwable instanceof AuthorizationChallengeException => [
                'WWW-Authenticate' => 'Session realm="VoltStack", error="authorization_required"',
            ],
            default => [],
        };
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

        if ($debug) {
            $extensions['metadata'] = $throwable->result()->metadata();
        }

        return $extensions;
    }
}
