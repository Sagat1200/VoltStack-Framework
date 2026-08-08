<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Throwable;

final class ControllerSecurityExceptionMapper implements ExceptionMapperInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
        $this->config = array_replace([
            'enabled' => true,
            'http_codes' => [
                'authentication_required' => 401,
                'authorization_denied' => 403,
                'tenant_violation' => 404,
                'exposure_violation' => 451,
                'infrastructure_failure' => 500,
            ],
            'expose_safe_context' => false,
            'expose_reason_code' => true,
            'expose_challenge_headers' => true,
            'expose_error_extensions' => true,
            'include_security_type_links' => true,
        ], $this->config);
    }

    public function statusCode(Throwable $throwable): ?int
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        return match (true) {
            $throwable instanceof AuthenticationRequiredException => (int) ($this->config['http_codes']['authentication_required'] ?? 401),
            $throwable instanceof AuthorizationDeniedException => (int) ($this->config['http_codes']['authorization_denied'] ?? 403),
            $throwable instanceof TenantViolationException => (int) ($this->config['http_codes']['tenant_violation'] ?? 404),
            $throwable instanceof ControllerExposureViolationException => (int) ($this->config['http_codes']['exposure_violation'] ?? 451),
            $throwable instanceof SecurityInfrastructureFailureException => (int) ($this->config['http_codes']['infrastructure_failure'] ?? 500),
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function headers(Throwable $throwable): array
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return [];
        }

        $headers = [];

        if (($this->config['expose_challenge_headers'] ?? true) && $throwable instanceof AuthenticationRequiredException) {
            $challenge = $throwable->challengeMetadata;
            $reqStrength = $challenge['required_strength_value'] ?? null;
            $parts = [
                'Bearer',
                'realm="api"',
            ];
            if (is_int($reqStrength)) {
                $parts[] = 'required_strength_value="' . $reqStrength . '"';
            }
            if (is_string($challenge['required_strength_name'] ?? null)) {
                $parts[] = 'required_strength="' . $challenge['required_strength_name'] . '"';
            }
            $curStrength = $challenge['current_strength_value'] ?? null;
            if (is_int($curStrength)) {
                $parts[] = 'current_strength_value="' . $curStrength . '"';
            }
            $error = 'unauthorized';
            if (($throwable->reasonCode ?? '') === 'authentication_strength_insufficient') {
                $error = 'insufficient_strength';
            }
            $parts[] = 'error="' . $error . '"';
            $headers['WWW-Authenticate'] = implode(', ', $parts);
        }

        if ($throwable instanceof TenantViolationException && (int) ($this->config['http_codes']['tenant_violation'] ?? 404) === 404) {
            $headers['X-Volt-Tenant-Hidden'] = '1';
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonExtensions(Throwable $throwable, bool $debug): array
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return [];
        }

        return $this->buildExtensions($throwable, $debug);
    }

    /**
     * @return array<string, mixed>
     */
    public function voltExtensions(Throwable $throwable, bool $debug): array
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return [];
        }

        return $this->buildExtensions($throwable, $debug);
    }

    public function message(Throwable $throwable, int $status): ?string
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        return match (true) {
            $throwable instanceof AuthenticationRequiredException => ($throwable->getMessage() !== '' && $throwable->getMessage() !== 'Authentication required')
                ? $throwable->getMessage()
                : 'Authentication is required to access this resource.',
            $throwable instanceof AuthorizationDeniedException => ($throwable->getMessage() !== '' && $throwable->getMessage() !== 'Authorization denied')
                ? $throwable->getMessage()
                : 'You do not have permission to access this resource.',
            $throwable instanceof TenantViolationException => 'Not Found',
            $throwable instanceof ControllerExposureViolationException => 'Access to this resource is restricted by exposure policy.',
            $throwable instanceof SecurityInfrastructureFailureException => 'Internal Server Error',
            default => null,
        };
    }

    public function errorCode(Throwable $throwable, int $status): ?string
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        return match (true) {
            $throwable instanceof AuthenticationRequiredException => match (true) {
                ($throwable->reasonCode ?? '') !== '' => 'controller.security.authentication.' . $throwable->reasonCode,
                default => 'controller.security.authentication_required',
            },
            $throwable instanceof AuthorizationDeniedException => match (true) {
                ($throwable->reasonCode ?? '') !== '' => 'controller.security.authorization.' . $throwable->reasonCode,
                default => 'controller.security.authorization_denied',
            },
            $throwable instanceof TenantViolationException => 'controller.security.tenant_violation',
            $throwable instanceof ControllerExposureViolationException => match (true) {
                ($throwable->reasonCode ?? '') !== '' => 'controller.security.exposure.' . $throwable->reasonCode,
                default => 'controller.security.controller_exposure_violation',
            },
            $throwable instanceof SecurityInfrastructureFailureException => 'controller.security.infrastructure_failure',
            default => null,
        };
    }

    public function htmlBody(Throwable $throwable, int $status): ?string
    {
        if (($this->config['enabled'] ?? true) !== true) {
            return null;
        }

        $reason = $this->exposedReasonCode($throwable);
        $ext = '';
        if ($reason !== null) {
            $ext = '<p><code style="opacity:0.8;">reason_code: ' . htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
        }
        if ($this->shouldExposeSafeContextHtml($throwable)) {
            $ctx = $this->getSafeContext($throwable);
            if ($ctx !== []) {
                $ext .= '<details style="margin-top:16px;"><summary style="cursor:pointer;opacity:0.85;">Safe Context (debug)</summary><pre style="background:#1e293b;padding:12px;border-radius:8px;overflow:auto;max-width:100%;font-size:12px;">'
                    . htmlspecialchars(json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</pre></details>';
            }
        }

        return match (true) {
            $throwable instanceof AuthenticationRequiredException => '<p>Authentication is required to access this resource. Please sign in with a method that satisfies the requirements and try again.</p>' . $ext,
            $throwable instanceof AuthorizationDeniedException => '<p>You do not have permission to perform this action. Additional privileges may be required.</p>' . $ext,
            $throwable instanceof TenantViolationException => '<p>The requested page could not be found.</p>' . $ext,
            $throwable instanceof ControllerExposureViolationException => '<p>Access to this resource is restricted by the application exposure policy.</p>' . $ext,
            $throwable instanceof SecurityInfrastructureFailureException => '<p>An unexpected error occurred while processing the request.</p>',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExtensions(Throwable $throwable, bool $debug): array
    {
        $extensions = [];

        if (($this->config['include_security_type_links'] ?? true)) {
            $typeLink = match (true) {
                $throwable instanceof AuthenticationRequiredException => 'https://voltstack.local/docs/errors/security/authentication-required',
                $throwable instanceof AuthorizationDeniedException => 'https://voltstack.local/docs/errors/security/authorization-denied',
                $throwable instanceof TenantViolationException => 'https://voltstack.local/docs/errors/security/tenant-violation',
                $throwable instanceof ControllerExposureViolationException => 'https://voltstack.local/docs/errors/security/exposure-violation',
                $throwable instanceof SecurityInfrastructureFailureException => 'https://voltstack.local/docs/errors/security/infrastructure-failure',
                default => null,
            };
            if ($typeLink !== null) {
                $extensions['type'] = $typeLink;
            }
        }

        if (($this->config['expose_reason_code'] ?? true)) {
            $reason = $this->exposedReasonCode($throwable);
            if ($reason !== null) {
                $extensions['reason_code'] = $reason;
            }
        }

        if ($this->shouldExposeSafeContext($throwable, $debug)) {
            $ctx = $this->getSafeContext($throwable);
            if ($ctx !== []) {
                $extensions['safe_context'] = $ctx;
            }
        }

        if (($this->config['expose_error_extensions'] ?? true) && $throwable instanceof AuthenticationRequiredException) {
            $challenge = $throwable->challengeMetadata;
            if ($challenge !== []) {
                $extensions['challenge'] = $challenge;
            }
            if (is_string($throwable->reasonCode) && $throwable->reasonCode !== '') {
                $extensions['error'] = $throwable->reasonCode;
            }
        }

        if (($this->config['expose_error_extensions'] ?? true) && $throwable instanceof AuthorizationDeniedException && $throwable->reasonCode !== '') {
            $extensions['policy_id'] = $throwable->safeContext['policy_id'] ?? null;
            $extensions = array_filter($extensions, static fn($v) => $v !== null);
        }

        return $extensions;
    }

    private function exposedReasonCode(Throwable $throwable): ?string
    {
        if (($this->config['expose_reason_code'] ?? true) !== true) {
            return null;
        }
        return match (true) {
            $throwable instanceof AuthenticationRequiredException => $throwable->reasonCode !== '' ? $throwable->reasonCode : null,
            $throwable instanceof AuthorizationDeniedException => $throwable->reasonCode !== '' ? $throwable->reasonCode : null,
            $throwable instanceof ControllerExposureViolationException => $throwable->reasonCode !== '' ? $throwable->reasonCode : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getSafeContext(Throwable $throwable): array
    {
        if ($throwable instanceof AuthenticationRequiredException) {
            return $throwable->safeContext;
        }
        if ($throwable instanceof AuthorizationDeniedException) {
            return $throwable->safeContext;
        }
        if ($throwable instanceof ControllerExposureViolationException) {
            return $throwable->safeContext;
        }
        if ($throwable instanceof SecurityInfrastructureFailureException) {
            if (method_exists($throwable, 'getSafeContext')) {
                return (array) $throwable->getSafeContext();
            }
            return [];
        }
        return [];
    }

    private function shouldExposeSafeContext(Throwable $throwable, bool $debug): bool
    {
        if ($throwable instanceof SecurityInfrastructureFailureException) {
            return false;
        }
        $global = $this->config['expose_safe_context'] ?? false;
        if ($global === true) {
            return true;
        }
        if ($global === 'debug' && $debug === true) {
            return true;
        }
        return $debug === true;
    }

    private function shouldExposeSafeContextHtml(Throwable $throwable): bool
    {
        if ($throwable instanceof SecurityInfrastructureFailureException) {
            return false;
        }
        $global = $this->config['expose_safe_context'] ?? false;
        return $global === true;
    }
}
