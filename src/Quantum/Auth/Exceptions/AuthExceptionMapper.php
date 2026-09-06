<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Throwable;

final class AuthExceptionMapper implements ExceptionMapperInterface
{
    public function statusCode(Throwable $throwable): ?int
    {
        return match (true) {
            $throwable instanceof GuestOnlyException => 403,
            $throwable instanceof StaleAuthenticationSessionException => 401,
            default => null,
        };
    }

    public function headers(Throwable $throwable): array
    {
        return [];
    }

    public function jsonExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->reasonCodeExtension($throwable);
    }

    public function voltExtensions(Throwable $throwable, bool $debug): array
    {
        return $this->reasonCodeExtension($throwable);
    }

    public function message(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException,
            $throwable instanceof StaleAuthenticationSessionException => $throwable->getMessage(),
            default => null,
        };
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException,
            $throwable instanceof StaleAuthenticationSessionException => $throwable->reasonCode,
            default => null,
        };
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException => '<p>This resource is only available to guest users.</p>',
            $throwable instanceof StaleAuthenticationSessionException => '<p>The authentication session is stale, expired or invalid. Please authenticate again.</p>',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function reasonCodeExtension(Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof GuestOnlyException,
            $throwable instanceof StaleAuthenticationSessionException => [
                'reason_code' => $throwable->reasonCode,
            ],
            default => [],
        };
    }
}
