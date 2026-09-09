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
            $throwable instanceof RevokedAuthenticationSessionException => 401,
            $throwable instanceof StaleAuthenticationSessionException => 401,
            $throwable instanceof StepUpRequiredException => 403,
            default => null,
        };
    }

    public function headers(Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof StepUpRequiredException => [
                'X-Auth-Step-Up' => 'required',
                'X-Auth-Required-Strength' => $throwable->requiredStrength->name,
            ],
            default => [],
        };
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
            $throwable instanceof RevokedAuthenticationSessionException,
            $throwable instanceof StaleAuthenticationSessionException,
            $throwable instanceof StepUpRequiredException => $throwable->getMessage(),
            default => null,
        };
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException,
            $throwable instanceof RevokedAuthenticationSessionException,
            $throwable instanceof StaleAuthenticationSessionException,
            $throwable instanceof StepUpRequiredException => $throwable->reasonCode,
            default => null,
        };
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException => '<p>This resource is only available to guest users.</p>',
            $throwable instanceof RevokedAuthenticationSessionException => '<p>The authentication session has been revoked. Please authenticate again.</p>',
            $throwable instanceof StaleAuthenticationSessionException => '<p>The authentication session is stale, expired or invalid. Please authenticate again.</p>',
            $throwable instanceof StepUpRequiredException => '<p>This resource requires elevated authentication. Complete step-up authentication and try again.</p>',
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
            $throwable instanceof RevokedAuthenticationSessionException,
            $throwable instanceof StaleAuthenticationSessionException => [
                'reason_code' => $throwable->reasonCode,
            ],
            $throwable instanceof StepUpRequiredException => [
                'reason_code' => $throwable->reasonCode,
                'required_strength_name' => $throwable->requiredStrength->name,
                'required_strength_value' => $throwable->requiredStrength->value,
                'current_strength_name' => $throwable->currentStrength->name,
                'current_strength_value' => $throwable->currentStrength->value,
            ],
            default => [],
        };
    }
}
