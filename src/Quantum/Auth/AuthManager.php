<?php

declare(strict_types=1);

namespace Quantum\Auth;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Exceptions\AuthenticationException;
use Quantum\Auth\Exceptions\IdentityNotEligibleException;
use Quantum\Auth\Exceptions\InvalidSecondFactorException;
use Quantum\Auth\Exceptions\InvalidCredentialsException;
use Quantum\Auth\Exceptions\RevokedAuthenticationSessionException;
use Quantum\Auth\Exceptions\SecondFactorNotAvailableException;
use Quantum\Auth\Exceptions\SecondFactorRequiredException;
use Quantum\Auth\Exceptions\StaleAuthenticationSessionException;
use Quantum\Auth\Exceptions\StepUpAuthenticationRequiredException;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Sessions\AuthenticationSessionPublicId;
use Quantum\Auth\Sessions\AuthenticationSessionSummary;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Auth\Support\AuthenticationHttpState;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
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
        try {
            $this->attemptOrFail($credentials);
        } catch (AuthenticationException) {
            return false;
        }

        return true;
    }

    public function attemptOrFail(array $credentials): void
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
            throw $this->exceptionFromDecision($decision->metadata);
        }

        $this->login($decision->context);
    }

    public function stepUp(array $credentials): bool
    {
        try {
            $this->stepUpOrFail($credentials);
        } catch (AuthenticationException) {
            return false;
        }

        return true;
    }

    public function stepUpOrFail(array $credentials): void
    {
        $current = $this->context();

        if ($current === null) {
            throw new StepUpAuthenticationRequiredException();
        }

        if ($current->authenticationStrength()->value >= AuthenticationStrength::MultiFactor->value) {
            return;
        }

        $decision = $this->orchestrator->execute(
            new AuthenticationOperationContext(
                operation: 'step_up',
                request: new AuthenticationRequest(
                    requestId: $this->runtimeContext()->requestId(),
                    transport: 'runtime',
                    attributes: ['credentials' => $credentials],
                ),
                currentContext: $current,
            ),
        );

        if (! $decision->isAuthenticated() || $decision->context === null) {
            throw $this->exceptionFromDecision($decision->metadata);
        }

        $this->login($decision->context);
    }

    public function login(mixed $user): void
    {
        $this->sessions->purgeExpired();
        $this->rememberRecoveryFailureReason(null);

        $context = $user instanceof AuthenticationContext
            ? $user
            : $this->contextFromUser($user);

        $existingSessionId = $this->activeSessionId();

        if ($existingSessionId !== null && $existingSessionId !== '') {
            $this->sessions->delete($existingSessionId);
        }

        if ($this->revokeOtherSessionsOnLogin()) {
            $this->sessions->deleteForIdentity($context->identity, $existingSessionId);
        }

        $sessionId = AuthenticationSessionId::generate();
        $sessionPublicId = AuthenticationSessionPublicId::generate();
        $sessionContext = $this->withSessionContext($context, $sessionId, $sessionPublicId);

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
            $this->rememberRecoveryFailureReason(null);
            return $current;
        }

        $request = $this->runtimeContext()->request();
        $sessionId = $request->cookie($this->sessionCookieName());

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $sessionId = $request->header('X-Auth-Session');
        }

        if (! is_string($sessionId) || trim($sessionId) === '') {
            $this->rememberRecoveryFailureReason(null);
            return null;
        }

        $request = new AuthenticationRequest(
            requestId: $this->runtimeContext()->requestId(),
            transport: 'runtime',
            attributes: [
                'session_id' => trim($sessionId),
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
            $this->rememberRecoveryFailureReason(null);
            $resolvedSessionId = $decision->metadata['session_id'] ?? $decision->context->attribute('session_id');
            $resolvedSessionId = is_string($resolvedSessionId) && trim($resolvedSessionId) !== ''
                ? trim($resolvedSessionId)
                : null;

            if ($resolvedSessionId !== null && $this->rotateSessionOnRecover()) {
                $this->login($decision->context);
            } else {
                $this->accessor->put($decision->context);

                if ($resolvedSessionId !== null) {
                    $this->runtimeContext()->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, $resolvedSessionId);
                }
            }
        } else {
            $this->rememberRecoveryFailureReason(
                is_string($decision->metadata['reason'] ?? null) ? trim((string) $decision->metadata['reason']) : null,
            );
            $this->queueLogoutCookie();
        }

        return $this->accessor->get() ?? $decision->context;
    }

    public function recoveryFailureReason(): ?string
    {
        $reason = $this->runtimeContext()->get(AuthenticationHttpState::RECOVERY_FAILURE_REASON_KEY);

        return is_string($reason) && trim($reason) !== ''
            ? trim($reason)
            : null;
    }

    public function currentSession(): ?AuthenticationSessionSummary
    {
        $context = $this->context();

        if ($context === null) {
            return null;
        }

        $sessionId = $context->attribute('session_id');
        $resolvedSession = is_string($sessionId) && trim($sessionId) !== ''
            ? $this->sessions->find(trim($sessionId))
            : null;

        if ($resolvedSession !== null) {
            return $this->toSessionSummary($resolvedSession, true);
        }

        $publicId = $context->sessionPublicId();

        if ($publicId === null) {
            return null;
        }

        return new AuthenticationSessionSummary(
            publicId: $publicId,
            method: $context->method,
            issuedAt: time(),
            expiresAt: null,
            current: true,
        );
    }

    public function sessions(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        $this->sessions->purgeExpired();
        $currentSessionId = $this->activeSessionId() ?? (is_string($context->attribute('session_id')) ? trim((string) $context->attribute('session_id')) : null);
        $summaries = [];

        foreach ($this->sessions->listForIdentity($context->identity) as $session) {
            $summary = $this->toSessionSummary(
                $session,
                $currentSessionId !== null && $session->id->value === $currentSessionId,
            );

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        usort($summaries, static function (AuthenticationSessionSummary $left, AuthenticationSessionSummary $right): int {
            if ($left->current !== $right->current) {
                return $left->current ? -1 : 1;
            }

            return $right->issuedAt <=> $left->issuedAt;
        });

        return $summaries;
    }

    public function revokeSession(string $publicId): bool
    {
        $context = $this->context();
        $publicId = trim($publicId);

        if ($context === null || $publicId === '') {
            return false;
        }

        foreach ($this->sessions->listForIdentity($context->identity) as $session) {
            if ($session->publicId() !== $publicId) {
                continue;
            }

            $this->sessions->delete($session->id->value);

            if ($this->activeSessionId() === $session->id->value) {
                $this->accessor->clear();
                $this->rememberRecoveryFailureReason(null);
                $this->runtimeContext()->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, null);
                $this->queueLogoutCookie();
                $this->runtimeContext()->set(AuthenticationHttpState::PENDING_SESSION_HEADER_KEY, 'cleared');
            }

            return true;
        }

        return false;
    }

    public function revokeOtherSessions(): int
    {
        $context = $this->context();

        if ($context === null) {
            return 0;
        }

        $currentSessionId = $this->activeSessionId() ?? (is_string($context->attribute('session_id')) ? trim((string) $context->attribute('session_id')) : null);
        $sessions = $this->sessions->listForIdentity($context->identity);
        $revoked = 0;

        foreach ($sessions as $session) {
            if ($currentSessionId !== null && $session->id->value === $currentSessionId) {
                continue;
            }

            $revoked++;
        }

        if ($revoked > 0) {
            $this->sessions->deleteForIdentity($context->identity, $currentSessionId);
        }

        return $revoked;
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
        $this->rememberRecoveryFailureReason(null);
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

    private function withSessionContext(
        AuthenticationContext $context,
        AuthenticationSessionId $sessionId,
        AuthenticationSessionPublicId $publicId,
    ): AuthenticationContext
    {
        return new AuthenticationContext(
            identity: $context->identity,
            reference: $context->reference instanceof IdentityReference
                ? $context->reference
                : new IdentityReference($context->identity->identifier(), $context->identity->type()),
            requestId: $context->requestId,
            method: $context->method,
            attributes: AuthenticationAssurance::enrichAttributes(
                array_merge($context->attributes, [
                    'session_id' => $sessionId->value,
                    'session_public_id' => $publicId->value,
                ]),
                $context->method,
            ),
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

    private function rotateSessionOnRecover(): bool
    {
        return (bool) $this->config->get('auth.session.rotate_on_recover', false);
    }

    private function revokeOtherSessionsOnLogin(): bool
    {
        return (bool) $this->config->get('auth.session.revoke_others_on_login', false);
    }

    private function activeSessionId(): ?string
    {
        $runtime = $this->runtimeContext();
        $sessionId = $runtime->get(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY);

        if (is_string($sessionId) && trim($sessionId) !== '') {
            return trim($sessionId);
        }

        $requestSessionId = $runtime->request()->cookie($this->sessionCookieName());

        return is_string($requestSessionId) && trim($requestSessionId) !== ''
            ? trim($requestSessionId)
            : null;
    }

    private function queueLogoutCookie(): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::PENDING_SESSION_COOKIE_KEY,
            AuthenticationHttpState::logoutCookie($this->sessionCookieName()),
        );
    }

    private function rememberRecoveryFailureReason(?string $reason): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::RECOVERY_FAILURE_REASON_KEY,
            is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        );
    }

    private function toSessionSummary(AuthenticationSession $session, bool $current): ?AuthenticationSessionSummary
    {
        $publicId = $session->publicId();

        if ($publicId === null) {
            return null;
        }

        return new AuthenticationSessionSummary(
            publicId: $publicId,
            method: $session->method,
            issuedAt: $session->issuedAt,
            expiresAt: $session->expiresAt,
            current: $current,
            label: $session->label(),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function exceptionFromDecision(array $metadata): AuthenticationException
    {
        $reason = (string) ($metadata['reason'] ?? 'auth.failed');

        return match ($reason) {
            'identity_not_eligible' => new IdentityNotEligibleException(
                \Quantum\Auth\Identity\IdentitySecurityState::from((string) ($metadata['security_state'] ?? 'disabled')),
            ),
            'step_up_requires_authentication' => new StepUpAuthenticationRequiredException(),
            'second_factor_not_available' => new SecondFactorNotAvailableException(),
            'second_factor_required' => new SecondFactorRequiredException(),
            'invalid_second_factor' => new InvalidSecondFactorException(),
            'session_revoked' => new RevokedAuthenticationSessionException(),
            'session_expired', 'session_not_found' => new StaleAuthenticationSessionException(),
            'invalid_credentials', 'missing_credentials' => new InvalidCredentialsException(),
            default => new AuthenticationException('Authentication failed.', 'auth.failed'),
        };
    }
}
