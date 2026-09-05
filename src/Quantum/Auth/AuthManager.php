<?php

declare(strict_types=1);

namespace Quantum\Auth;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Support\AuthenticationHttpState;
use Quantum\Config\ConfigRepository;
use RuntimeException;
use VoltStack\Runtime\Context\RuntimeContext;

final class AuthManager implements AuthenticationManagerInterface
{
    public function __construct(
        private readonly AuthenticationContextAccessor $accessor,
        private readonly AuthenticationOrchestratorInterface $orchestrator,
        private readonly AuthenticationSessionRepositoryInterface $sessions,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @param array<string, mixed> $credentials
     */
    public function attempt(array $credentials): bool
    {
        $decision = $this->orchestrator->execute(
            new AuthenticationOperationContext(
                operation: 'authenticate',
                request: new AuthenticationRequest(
                    requestId: $this->runtimeContext()->requestId(),
                    transport: 'runtime',
                    attributes: ['credentials' => $credentials],
                ),
            ),
        );

        if (! $decision->isAuthenticated() || $decision->context === null) {
            return false;
        }

        $this->login($decision->context);

        return true;
    }

    public function login(mixed $user): void
    {
        $context = $user instanceof AuthenticationContext
            ? $user
            : $this->contextFromUser($user);

        $sessionId = AuthenticationSessionId::generate();
        $sessionContext = $this->withSessionContext($context, $sessionId);

        $this->sessions->save(new AuthenticationSession(
            id: $sessionId,
            identity: $sessionContext->identity,
            reference: $sessionContext->reference,
            method: $sessionContext->method,
            issuedAt: time(),
            expiresAt: $this->sessionExpiresAt(),
            attributes: $sessionContext->attributes,
        ));

        $this->accessor->put($sessionContext);

        $runtime = $this->runtimeContext();
        $runtime->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, $sessionId->value);
        $runtime->set(AuthenticationHttpState::PENDING_SESSION_COOKIE_KEY, AuthenticationHttpState::loginCookie(
            $sessionId->value,
            $this->sessionCookieName(),
            $this->sessionLifetime(),
        ));
        $runtime->set(AuthenticationHttpState::PENDING_SESSION_HEADER_KEY, $sessionId->value);
    }

    public function user(): mixed
    {
        return $this->context()?->identity;
    }

    public function setUser(mixed $user): void
    {
        $this->accessor->putUser($user);
    }

    public function check(): bool
    {
        return $this->context() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): mixed
    {
        $user = $this->user();

        if (is_object($user) && isset($user->id)) {
            return $user->id;
        }

        if (is_array($user) && array_key_exists('id', $user)) {
            return $user['id'];
        }

        if ($user instanceof \Quantum\Auth\Identity\IdentityInterface) {
            if ($user instanceof \Quantum\Auth\Identity\GenericIdentity && array_key_exists('_legacy_id', $user->attributes)) {
                return $user->attributes['_legacy_id'];
            }

            return (string) $user->identifier();
        }

        return null;
    }

    public function context(): ?AuthenticationContext
    {
        $current = $this->accessor->get();
        if ($current !== null) {
            return $current;
        }

        $request = $this->runtimeContext()->request();
        $sessionId = $request->cookie($this->sessionCookieName());

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $sessionId = $request->header('X-Auth-Session');
        }

        $request = new AuthenticationRequest(
            requestId: $this->runtimeContext()->requestId(),
            transport: 'runtime',
            attributes: [
                'session_id' => is_string($sessionId) ? trim($sessionId) : null,
            ],
        );

        $decision = $this->orchestrator->execute(
            new AuthenticationOperationContext(
                operation: 'recover',
                request: $request,
                currentContext: $current,
            ),
        );

        if ($decision->isAuthenticated() && $decision->context !== null) {
            $this->accessor->put($decision->context);

            $resolvedSessionId = $decision->metadata['session_id'] ?? $decision->context->attribute('session_id');
            if (is_string($resolvedSessionId) && trim($resolvedSessionId) !== '') {
                $this->runtimeContext()->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, $resolvedSessionId);
            }
        } elseif (is_string($sessionId) && trim($sessionId) !== '') {
            $this->queueLogoutCookie();
        }

        return $decision->context;
    }

    public function logout(): void
    {
        $runtime = $this->runtimeContext();
        $sessionId = $runtime->get(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY);

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $requestSessionId = $runtime->request()->cookie($this->sessionCookieName());
            $sessionId = is_string($requestSessionId) ? trim($requestSessionId) : '';
        }

        if ($sessionId !== '') {
            $this->sessions->delete($sessionId);
        }

        $this->accessor->clear();
        $runtime->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, null);
        $this->queueLogoutCookie();
        $runtime->set(AuthenticationHttpState::PENDING_SESSION_HEADER_KEY, 'cleared');
    }

    private function runtimeContext(): RuntimeContext
    {
        $context = RuntimeContext::current();

        if ($context === null) {
            throw new RuntimeException('No active runtime context is available for auth access.');
        }

        return $context;
    }

    private function contextFromUser(mixed $user): AuthenticationContext
    {
        $this->accessor->putUser($user);
        $context = $this->accessor->get();

        if ($context === null) {
            throw new RuntimeException('Unable to create an authentication context from the given user.');
        }

        return $context;
    }

    private function withSessionContext(AuthenticationContext $context, AuthenticationSessionId $sessionId): AuthenticationContext
    {
        return new AuthenticationContext(
            identity: $context->identity,
            reference: $context->reference instanceof IdentityReference
                ? $context->reference
                : new IdentityReference($context->identity->identifier(), $context->identity->type()),
            requestId: $context->requestId,
            method: $context->method,
            attributes: $context->attributes + ['session_id' => $sessionId->value],
        );
    }

    private function sessionCookieName(): string
    {
        $configured = $this->config->get('auth.session.cookie', AuthenticationHttpState::SESSION_COOKIE_NAME);

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : AuthenticationHttpState::SESSION_COOKIE_NAME;
    }

    private function sessionLifetime(): int
    {
        $configured = $this->config->get('auth.session.lifetime', 3600);

        if (is_int($configured)) {
            return max(0, $configured);
        }

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return 3600;
    }

    private function sessionExpiresAt(): ?int
    {
        $lifetime = $this->sessionLifetime();

        return $lifetime > 0 ? time() + $lifetime : time();
    }

    private function queueLogoutCookie(): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::PENDING_SESSION_COOKIE_KEY,
            AuthenticationHttpState::logoutCookie($this->sessionCookieName()),
        );
    }
}
