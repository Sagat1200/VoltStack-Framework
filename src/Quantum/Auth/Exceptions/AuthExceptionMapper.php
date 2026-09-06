<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Throwable;

final class AuthExceptionMapper implements ExceptionMapperInterface
{
    public function statusCode(Throwable $throwable): ?int
    {
        return $throwable instanceof GuestOnlyException ? 403 : null;
    }

    public function headers(Throwable $throwable): array
    {
        return [];
    }

    public function jsonExtensions(Throwable $throwable, bool $debug): array
    {
        if (! $throwable instanceof GuestOnlyException) {
            return [];
        }

        return [
            'reason_code' => $throwable->reasonCode,
        ];
    }

    public function voltExtensions(Throwable $throwable, bool $debug): array
    {
        if (! $throwable instanceof GuestOnlyException) {
            return [];
        }

        return [
            'reason_code' => $throwable->reasonCode,
        ];
    }

    public function message(Throwable $throwable, int $status): ?string
    {
        if (! $throwable instanceof GuestOnlyException) {
            return null;
        }

        return $throwable->getMessage();
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        if (! $throwable instanceof GuestOnlyException) {
            return null;
        }

        return $throwable->reasonCode;
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        if (! $throwable instanceof GuestOnlyException) {
            return null;
        }

        return '<p>This resource is only available to guest users.</p>';
    }
}
