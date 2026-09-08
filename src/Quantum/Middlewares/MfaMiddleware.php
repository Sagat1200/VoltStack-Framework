<?php

declare(strict_types=1);

namespace Quantum\Middlewares;

use Closure;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Exceptions\AuthenticationRequiredException;
use Quantum\Auth\Exceptions\StaleAuthenticationSessionException;
use Quantum\Auth\Exceptions\StepUpRequiredException;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Http\Request;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;

final class MfaMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationManagerInterface $auth,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->auth->check()) {
            $currentStrength = $this->currentStrength();

            if ($currentStrength->value < AuthenticationStrength::MultiFactor->value) {
                throw new StepUpRequiredException(
                    requiredStrength: AuthenticationStrength::MultiFactor,
                    currentStrength: $currentStrength,
                );
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
}
