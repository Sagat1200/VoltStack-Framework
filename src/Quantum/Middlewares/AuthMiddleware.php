<?php

declare(strict_types=1);

namespace Quantum\Middlewares;

use Closure;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Exceptions\AuthenticationRequiredException;
use Quantum\Auth\Exceptions\StaleAuthenticationSessionException;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException as ControllerAuthenticationRequiredException;
use Quantum\Http\Request;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationManagerInterface $auth,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->auth->check()) {
            $requiredStrength = $this->requiredStrength($request);

            if ($requiredStrength !== null) {
                $currentStrength = $this->currentStrength();

                if ($currentStrength->value < $requiredStrength->value) {
                    throw new ControllerAuthenticationRequiredException(
                        reasonCode: 'authentication_strength_insufficient',
                        challengeMetadata: [
                            'required_strength_value' => $requiredStrength->value,
                            'required_strength_name' => $requiredStrength->name,
                            'current_strength_value' => $currentStrength->value,
                            'current_strength_name' => $currentStrength->name,
                        ],
                        safeContext: [
                            'policy_id' => 'auth.middleware.strength',
                            'reason_code' => 'authentication_strength_insufficient',
                            'required_strength_value' => $requiredStrength->value,
                            'current_strength_value' => $currentStrength->value,
                        ],
                        message: 'Authentication strength is insufficient for this resource.',
                    );
                }
            }

            return $next($request);
        }

        if ($this->hasSessionCredential($request)) {
            throw new StaleAuthenticationSessionException();
        }

        throw new AuthenticationRequiredException();
    }

    private function hasSessionCredential(Request $request): bool
    {
        $cookieName = $this->sessionCookieName();
        $cookieValue = $request->cookie($cookieName);

        if (is_string($cookieValue) && trim($cookieValue) !== '') {
            return true;
        }

        $headerValue = $request->header('X-Auth-Session');

        return is_string($headerValue) && trim($headerValue) !== '';
    }

    private function sessionCookieName(): string
    {
        $configured = $this->config->get('auth.session.cookie');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'voltstack_auth_session';
    }

    private function currentStrength(): AuthenticationStrength
    {
        $context = $this->auth->context();

        if ($context === null) {
            return AuthenticationStrength::Anonymous;
        }

        return $context->authenticationStrength();
    }

    private function requiredStrength(Request $request): ?AuthenticationStrength
    {
        $authMeta = $request->routeMeta('auth');

        if ($authMeta === null || $authMeta === false) {
            return null;
        }

        if (is_array($authMeta)) {
            return $this->normalizeStrength(
                $authMeta['minimum_strength']
                    ?? $authMeta['required_strength']
                    ?? $authMeta['minimum_strength_value']
                    ?? $authMeta['required_strength_value']
                    ?? null,
            ) ?? AuthenticationStrength::Password;
        }

        return $this->normalizeStrength($authMeta);
    }

    private function normalizeStrength(mixed $value): ?AuthenticationStrength
    {
        if ($value === true) {
            return AuthenticationStrength::Password;
        }

        return AuthenticationAssurance::resolveExplicitStrength($value);
    }
}
