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
            $throwable instanceof FreshAuthenticationRequiredException => 403,
            $throwable instanceof RevokedAuthenticationSessionException => 401,
            $throwable instanceof StaleAuthenticationSessionException => 401,
            $throwable instanceof StepUpRequiredException => 403,
            $throwable instanceof CredentialLockedException => 423,
            $throwable instanceof AccountSuspendedException => 403,
            $throwable instanceof PasswordExpiredException,
            $throwable instanceof PasswordRotationRequiredException => 401,
            $throwable instanceof ThrottleDeniedException => 429,
            $throwable instanceof RiskDeniedException => 403,
            $throwable instanceof AssuranceInsufficientException => 423,
            default => null,
        };
    }

    public function headers(Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof FreshAuthenticationRequiredException => [
                'X-Auth-Reauthenticate' => 'required',
                'X-Auth-Fresh-Window' => (string) $throwable->freshWindowSeconds,
                'X-Auth-Operation' => $throwable->operation,
            ],
            $throwable instanceof StepUpRequiredException => array_filter([
                'X-Auth-Step-Up' => 'required',
                'X-Auth-Required-Strength' => $throwable->requiredStrength->name,
                'X-Auth-Current-Strength' => $throwable->currentStrength->name,
                'X-Auth-Operation' => $throwable->operation,
                'X-Auth-Risk-Score' => $throwable->riskScore !== null ? (string) $throwable->riskScore : null,
                'X-Auth-Risk-Level' => $throwable->riskLevel,
                'X-Auth-Assurance-Required-Min' => $throwable->requiredMinAssurance !== null ? (string) $throwable->requiredMinAssurance : null,
                'X-Auth-Assurance-Current' => $throwable->currentAssurance !== null ? (string) $throwable->currentAssurance : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof CredentialLockedException => array_filter([
                'X-Auth-Credential-Locked' => 'true',
                'X-Auth-Lockout-Until' => $throwable->lockoutUntil !== null ? (string) $throwable->lockoutUntil : null,
                'X-Auth-Failed-Attempts' => (string) $throwable->failedAttempts,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof AccountSuspendedException => [
                'X-Auth-Account-Suspended' => 'true',
            ],
            $throwable instanceof PasswordExpiredException => array_filter([
                'X-Auth-Password-Expired' => 'true',
                'X-Auth-Password-Created-At' => $throwable->passwordCreatedAt > 0 ? (string) $throwable->passwordCreatedAt : null,
                'X-Auth-Password-Expires-At' => $throwable->expiresAt !== null ? (string) $throwable->expiresAt : null,
                'X-Auth-Password-Expires-After' => $throwable->expiresAfterSeconds !== null ? (string) $throwable->expiresAfterSeconds : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof PasswordRotationRequiredException => [
                'X-Auth-Password-Rotation-Required' => 'true',
                'X-Auth-Password-Created-At' => (string) $throwable->passwordCreatedAt,
                'X-Auth-Rotation-Window' => (string) $throwable->rotationWindowSeconds,
                'X-Auth-Password-Age-Seconds' => (string) $throwable->ageSeconds,
            ],
            $throwable instanceof ThrottleDeniedException => array_filter([
                'Retry-After' => $throwable->retryAfterSeconds > 0 ? (string) $throwable->retryAfterSeconds : null,
                'X-Auth-Throttle-Denied' => 'true',
                'X-Auth-Throttle-Retry-After' => (string) $throwable->retryAfterSeconds,
                'X-Auth-Throttle-Identifier' => $throwable->identifier,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof RiskDeniedException => array_filter([
                'X-Auth-Risk-Denied' => 'true',
                'X-Auth-Risk-Score' => (string) $throwable->riskScore,
                'X-Auth-Risk-Deny-Threshold' => (string) $throwable->denyThreshold,
                'X-Auth-Risk-Level' => is_string($throwable->metadata['risk_level'] ?? null) ? $throwable->metadata['risk_level'] : null,
                'X-Auth-Operation' => is_string($throwable->metadata['operation'] ?? null) ? $throwable->metadata['operation'] : null,
                'X-Auth-Assurance-Current' => isset($throwable->metadata['current_assurance']) ? (string) $throwable->metadata['current_assurance'] : null,
                'X-Auth-Assurance-Required-Min' => isset($throwable->metadata['required_min_assurance']) ? (string) $throwable->metadata['required_min_assurance'] : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof AssuranceInsufficientException => array_filter([
                'X-Auth-Assurance-Insufficient' => 'true',
                'X-Auth-Assurance-Required-Min' => (string) $throwable->requiredMinAssurance,
                'X-Auth-Assurance-Current' => (string) $throwable->currentAssurance,
                'X-Auth-Operation' => $throwable->operation,
                'X-Auth-Risk-Score' => $throwable->riskScore !== null ? (string) $throwable->riskScore : null,
                'X-Auth-Risk-Level' => $throwable->riskLevel,
                'X-Auth-Required-Strength' => $throwable->requiredStrengthName,
                'X-Auth-Current-Strength' => $throwable->currentStrengthName,
            ], static fn (mixed $v): bool => $v !== null),
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
            $throwable instanceof FreshAuthenticationRequiredException,
            $throwable instanceof RevokedAuthenticationSessionException,
            $throwable instanceof StaleAuthenticationSessionException,
            $throwable instanceof StepUpRequiredException,
            $throwable instanceof CredentialLockedException,
            $throwable instanceof AccountSuspendedException,
            $throwable instanceof PasswordExpiredException,
            $throwable instanceof PasswordRotationRequiredException,
            $throwable instanceof ThrottleDeniedException,
            $throwable instanceof RiskDeniedException,
            $throwable instanceof AssuranceInsufficientException => $throwable->getMessage(),
            default => null,
        };
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException,
            $throwable instanceof FreshAuthenticationRequiredException,
            $throwable instanceof RevokedAuthenticationSessionException,
            $throwable instanceof StaleAuthenticationSessionException,
            $throwable instanceof StepUpRequiredException,
            $throwable instanceof CredentialLockedException,
            $throwable instanceof AccountSuspendedException,
            $throwable instanceof PasswordExpiredException,
            $throwable instanceof PasswordRotationRequiredException,
            $throwable instanceof ThrottleDeniedException,
            $throwable instanceof RiskDeniedException,
            $throwable instanceof AssuranceInsufficientException => $throwable->reasonCode,
            default => null,
        };
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        return match (true) {
            $throwable instanceof GuestOnlyException => '<p>This resource is only available to guest users.</p>',
            $throwable instanceof FreshAuthenticationRequiredException => '<p>Fresh authentication is required before this security-sensitive operation can continue.</p>',
            $throwable instanceof RevokedAuthenticationSessionException => '<p>The authentication session has been revoked. Please authenticate again.</p>',
            $throwable instanceof StaleAuthenticationSessionException => '<p>The authentication session is stale, expired or invalid. Please authenticate again.</p>',
            $throwable instanceof StepUpRequiredException => '<p>This resource requires elevated authentication. Complete step-up authentication and try again.</p>',
            $throwable instanceof CredentialLockedException => '<p>Your credential has been temporarily locked due to repeated failed authentication attempts. Try again later or unlock your account.</p>',
            $throwable instanceof AccountSuspendedException => '<p>This account has been suspended and cannot be used to authenticate.</p>',
            $throwable instanceof PasswordExpiredException => '<p>Your password has expired. You must change it before continuing.</p>',
            $throwable instanceof PasswordRotationRequiredException => '<p>Password rotation is required. Please update your password to continue.</p>',
            $throwable instanceof ThrottleDeniedException => '<p>Too many failed authentication attempts have been detected for this account or IP address. Please wait before trying again.</p>',
            $throwable instanceof RiskDeniedException => '<p>This authentication attempt has been blocked by the adaptive risk policy. Please try again later or contact support.</p>',
            $throwable instanceof AssuranceInsufficientException => '<p>Your current authentication level is not sufficient to perform this operation. Please re-authenticate with a stronger assurance method (e.g. MFA, hardware key, passkey).</p>',
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
            $throwable instanceof StaleAuthenticationSessionException,
            $throwable instanceof CredentialLockedException,
            $throwable instanceof AccountSuspendedException => [
                'reason_code' => $throwable->reasonCode,
            ],
            $throwable instanceof FreshAuthenticationRequiredException => [
                'reason_code' => $throwable->reasonCode,
                'operation' => $throwable->operation,
                'fresh_window_seconds' => (string) $throwable->freshWindowSeconds,
            ],
            $throwable instanceof StepUpRequiredException => [
                'reason_code' => $throwable->reasonCode,
                'required_strength_name' => $throwable->requiredStrength->name,
                'required_strength_value' => (string) $throwable->requiredStrength->value,
                'current_strength_name' => $throwable->currentStrength->name,
                'current_strength_value' => (string) $throwable->currentStrength->value,
            ] + array_filter([
                'operation' => $throwable->operation,
                'risk_score' => $throwable->riskScore !== null ? (string) $throwable->riskScore : null,
                'risk_level' => $throwable->riskLevel,
                'required_min_assurance' => $throwable->requiredMinAssurance !== null ? (string) $throwable->requiredMinAssurance : null,
                'current_assurance' => $throwable->currentAssurance !== null ? (string) $throwable->currentAssurance : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof PasswordExpiredException => array_filter([
                'reason_code' => $throwable->reasonCode,
                'password_created_at' => $throwable->passwordCreatedAt > 0 ? (string) $throwable->passwordCreatedAt : null,
                'expires_at' => $throwable->expiresAt !== null ? (string) $throwable->expiresAt : null,
                'expires_after_seconds' => $throwable->expiresAfterSeconds !== null ? (string) $throwable->expiresAfterSeconds : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof PasswordRotationRequiredException => [
                'reason_code' => $throwable->reasonCode,
                'password_created_at' => (string) $throwable->passwordCreatedAt,
                'rotation_window_seconds' => (string) $throwable->rotationWindowSeconds,
                'age_seconds' => (string) $throwable->ageSeconds,
            ],
            $throwable instanceof ThrottleDeniedException => array_filter([
                'reason_code' => $throwable->reasonCode,
                'retry_after_seconds' => (string) $throwable->retryAfterSeconds,
                'throttle_identifier' => $throwable->identifier,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof RiskDeniedException => array_filter([
                'reason_code' => $throwable->reasonCode,
                'risk_score' => (string) $throwable->riskScore,
                'deny_threshold' => (string) $throwable->denyThreshold,
                'risk_level' => is_string($throwable->metadata['risk_level'] ?? null) ? $throwable->metadata['risk_level'] : null,
                'operation' => is_string($throwable->metadata['operation'] ?? null) ? $throwable->metadata['operation'] : null,
                'current_assurance' => isset($throwable->metadata['current_assurance']) ? (string) $throwable->metadata['current_assurance'] : null,
                'required_min_assurance' => isset($throwable->metadata['required_min_assurance']) ? (string) $throwable->metadata['required_min_assurance'] : null,
            ], static fn (mixed $v): bool => $v !== null),
            $throwable instanceof AssuranceInsufficientException => array_filter([
                'reason_code' => $throwable->reasonCode,
                'required_min_assurance' => (string) $throwable->requiredMinAssurance,
                'current_assurance' => (string) $throwable->currentAssurance,
                'operation' => $throwable->operation,
                'risk_score' => $throwable->riskScore !== null ? (string) $throwable->riskScore : null,
                'risk_level' => $throwable->riskLevel,
                'required_strength_name' => $throwable->requiredStrengthName,
                'current_strength_name' => $throwable->currentStrengthName,
            ], static fn (mixed $v): bool => $v !== null),
            default => [],
        };
    }
}
