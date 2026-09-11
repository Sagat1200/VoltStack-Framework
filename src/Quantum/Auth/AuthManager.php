<?php

declare(strict_types=1);

namespace Quantum\Auth;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDeviceCredentialValidationResult;
use Quantum\Auth\Devices\TrustedDeviceCredentialValidator;
use Quantum\Auth\Devices\DeviceInventorySummary;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Devices\TrustedDeviceSummary;
use Quantum\Auth\Exceptions\AuthenticationException;
use Quantum\Auth\Exceptions\FreshAuthenticationRequiredException;
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
        private readonly TrustedDeviceRepositoryInterface $trustedDeviceRepository,
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
        $request = $this->runtimeContext()->request();
        $decision = $this->orchestrator->execute(
            new AuthenticationOperationContext(
                operation: 'authenticate',
                request: new AuthenticationRequest(
                    requestId: $this->runtimeContext()->requestId(),
                    transport: 'runtime',
                    attributes: [
                        'credentials' => $credentials,
                        'trusted_device_credential' => $this->trustedDeviceCredentialFromRequest(),
                        'trusted_device_host' => $request->host(),
                        'trusted_device_user_agent' => $request->header('User-Agent'),
                        'trusted_device_accept_language' => $request->header('Accept-Language'),
                    ],
                ),
            ),
        );

        if ((bool) ($decision->metadata['trusted_device_invalid'] ?? false)) {
            $this->queueTrustedDeviceLogoutCookie();
        }

        if (! $decision->isAuthenticated() || $decision->context === null) {
            throw $this->exceptionFromDecision($decision->metadata);
        }

        $this->login($decision->context);
        $this->rotateTrustedDeviceAfterChallengeReduction($decision->metadata);
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
        $this->trustedDeviceRepository->purgeExpired();
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
        $this->touchTrustedDeviceForContext(
            $sessionContext,
            $this->trustedDeviceValidationForContext($sessionContext)->device,
        );

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
            $trustedDeviceValidation = $this->trustedDeviceValidationForContext($decision->context);
            $trustedDevice = $trustedDeviceValidation->device;
            $resolvedContext = $this->withTrustedDeviceContext(
                $decision->context,
                $trustedDevice,
                $trustedDeviceValidation->invalid,
            );

            if ($resolvedSessionId !== null && $this->rotateSessionOnRecover()) {
                $this->login($resolvedContext);
            } else {
                if ($resolvedSessionId !== null) {
                    $this->touchRecoveredSession(
                        $resolvedSessionId,
                        $trustedDevice,
                        $trustedDeviceValidation->invalid,
                    );
                }

                $this->accessor->put($resolvedContext);
                $this->touchTrustedDeviceForContext($resolvedContext, $trustedDevice);

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
        $trustedDeviceReferences = $this->trustedDeviceReferences($context->reference);

        if ($resolvedSession !== null) {
            return $this->toSessionSummary(
                $resolvedSession,
                true,
                false,
                $trustedDeviceReferences,
                $context->deviceTrustState(),
            );
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
            lastActivityAt: null,
            current: true,
            label: $this->contextStringAttribute($context, 'session_label'),
            clientFamily: $this->contextStringAttribute($context, 'session_client_family'),
            clientPlatform: $this->contextStringAttribute($context, 'session_client_platform'),
            deviceKind: $this->contextStringAttribute($context, 'session_device_kind'),
            deviceReference: $context->deviceReference(),
            deviceTrustState: $context->deviceTrustState(),
            ipPrefix: $this->contextStringAttribute($context, 'session_ip_prefix'),
            canRevoke: true,
            requiresReauthentication: false,
            revocationScope: 'current',
            revocationMode: 'direct',
        );
    }

    public function sessions(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        $this->sessions->purgeExpired();
        $this->trustedDeviceRepository->purgeExpired();
        $currentSessionId = $this->activeSessionId() ?? (is_string($context->attribute('session_id')) ? trim((string) $context->attribute('session_id')) : null);
        $requiresRemoteReauthentication = $this->requiresFreshAuthenticationHintForCurrentContext($context);
        $trustedDeviceReferences = $this->trustedDeviceReferences($context->reference);
        $summaries = [];

        foreach ($this->sessions->listForIdentity($context->identity) as $session) {
            $summary = $this->toSessionSummary(
                $session,
                $currentSessionId !== null && $session->id->value === $currentSessionId,
                $requiresRemoteReauthentication,
                $trustedDeviceReferences,
                $currentSessionId !== null && $session->id->value === $currentSessionId
                    ? $context->deviceTrustState()
                    : null,
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

    public function trustedDevices(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        $this->trustedDeviceRepository->purgeExpired();
        $currentDeviceReference = $context->deviceReference();
        $requiresRemoteReauthentication = $this->requiresFreshAuthenticationHintForCurrentTrustedDeviceContext($context);
        $summaries = [];

        foreach ($this->trustedDeviceRepository->listForIdentity($context->reference) as $device) {
            $current = $currentDeviceReference !== null && $device->deviceReference === $currentDeviceReference;
            $summaries[] = $this->toTrustedDeviceSummary(
                $device,
                $current,
                $requiresRemoteReauthentication,
            );
        }

        usort($summaries, static function (TrustedDeviceSummary $left, TrustedDeviceSummary $right): int {
            if ($left->current !== $right->current) {
                return $left->current ? -1 : 1;
            }

            return ($right->lastUsedAt ?? $right->issuedAt) <=> ($left->lastUsedAt ?? $left->issuedAt);
        });

        return $summaries;
    }

    public function devices(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        $entries = [];

        foreach ($this->sessions() as $session) {
            if ($session->deviceReference === null || trim($session->deviceReference) === '') {
                continue;
            }

            $deviceReference = trim($session->deviceReference);
            $lastSeenAt = $session->lastActivityAt ?? $session->issuedAt;

            if (! isset($entries[$deviceReference])) {
                $entries[$deviceReference] = [
                    'device_reference' => $deviceReference,
                    'trust_state' => $session->deviceTrustState,
                    'session_count' => 0,
                    'current_session_count' => 0,
                    'current' => false,
                    'has_trusted_device' => false,
                    'trusted_device_public_id' => null,
                    'session_public_ids' => [],
                    'last_seen_at' => $lastSeenAt,
                    'requires_reauthentication' => false,
                    'label' => $session->label,
                    'client_family' => $session->clientFamily,
                    'client_platform' => $session->clientPlatform,
                    'device_kind' => $session->deviceKind,
                ];
            }

            $entries[$deviceReference]['session_count']++;
            $entries[$deviceReference]['current_session_count'] += $session->current ? 1 : 0;
            $entries[$deviceReference]['current'] = $entries[$deviceReference]['current'] || $session->current;
            $entries[$deviceReference]['requires_reauthentication'] = $entries[$deviceReference]['requires_reauthentication']
                || (! $session->current && $session->requiresReauthentication);
            $entries[$deviceReference]['session_public_ids'][] = $session->publicId;
            $entries[$deviceReference]['last_seen_at'] = max(
                (int) ($entries[$deviceReference]['last_seen_at'] ?? 0),
                $lastSeenAt,
            );
            $entries[$deviceReference]['label'] = $this->preferredInventoryValue(
                $entries[$deviceReference]['label'] ?? null,
                $session->label,
            );
            $entries[$deviceReference]['client_family'] = $this->preferredInventoryValue(
                $entries[$deviceReference]['client_family'] ?? null,
                $session->clientFamily,
            );
            $entries[$deviceReference]['client_platform'] = $this->preferredInventoryValue(
                $entries[$deviceReference]['client_platform'] ?? null,
                $session->clientPlatform,
            );
            $entries[$deviceReference]['device_kind'] = $this->preferredInventoryValue(
                $entries[$deviceReference]['device_kind'] ?? null,
                $session->deviceKind,
            );

            if ($session->deviceTrustState === 'trusted') {
                $entries[$deviceReference]['trust_state'] = 'trusted';
            }
        }

        foreach ($this->trustedDevices() as $device) {
            $deviceReference = trim($device->deviceReference);

            if ($deviceReference === '') {
                continue;
            }

            $lastSeenAt = $device->lastUsedAt ?? $device->issuedAt;

            if (! isset($entries[$deviceReference])) {
                $entries[$deviceReference] = [
                    'device_reference' => $deviceReference,
                    'trust_state' => $device->trustState,
                    'session_count' => 0,
                    'current_session_count' => 0,
                    'current' => $device->current,
                    'has_trusted_device' => true,
                    'trusted_device_public_id' => $device->publicId,
                    'session_public_ids' => [],
                    'last_seen_at' => $lastSeenAt,
                    'requires_reauthentication' => ! $device->current && $device->requiresReauthentication,
                    'label' => $device->label,
                    'client_family' => $device->clientFamily,
                    'client_platform' => $device->clientPlatform,
                    'device_kind' => $device->deviceKind,
                ];
            } else {
                $entries[$deviceReference]['has_trusted_device'] = true;
                $entries[$deviceReference]['trusted_device_public_id'] = $device->publicId;
                $entries[$deviceReference]['current'] = $entries[$deviceReference]['current'] || $device->current;
                $entries[$deviceReference]['requires_reauthentication'] = $entries[$deviceReference]['requires_reauthentication']
                    || (! $device->current && $device->requiresReauthentication);
                $entries[$deviceReference]['last_seen_at'] = max(
                    (int) ($entries[$deviceReference]['last_seen_at'] ?? 0),
                    $lastSeenAt,
                );
                $entries[$deviceReference]['label'] = $this->preferredInventoryValue(
                    $entries[$deviceReference]['label'] ?? null,
                    $device->label,
                );
                $entries[$deviceReference]['client_family'] = $this->preferredInventoryValue(
                    $entries[$deviceReference]['client_family'] ?? null,
                    $device->clientFamily,
                );
                $entries[$deviceReference]['client_platform'] = $this->preferredInventoryValue(
                    $entries[$deviceReference]['client_platform'] ?? null,
                    $device->clientPlatform,
                );
                $entries[$deviceReference]['device_kind'] = $this->preferredInventoryValue(
                    $entries[$deviceReference]['device_kind'] ?? null,
                    $device->deviceKind,
                );
            }

            if ($device->trustState === 'trusted') {
                $entries[$deviceReference]['trust_state'] = 'trusted';
            }
        }

        $summaries = array_map(
            fn (array $entry): DeviceInventorySummary => $this->toDeviceInventorySummary($entry),
            array_values($entries),
        );

        usort($summaries, static function (DeviceInventorySummary $left, DeviceInventorySummary $right): int {
            if ($left->current !== $right->current) {
                return $left->current ? -1 : 1;
            }

            if ($left->hasTrustedDevice !== $right->hasTrustedDevice) {
                return $left->hasTrustedDevice ? -1 : 1;
            }

            return ($right->lastSeenAt ?? 0) <=> ($left->lastSeenAt ?? 0);
        });

        return $summaries;
    }

    public function trustCurrentDevice(?string $label = null): bool
    {
        $context = $this->context();

        if ($context === null) {
            return false;
        }

        $this->trustedDeviceRepository->purgeExpired();

        if ($this->trustedDevicesRequireMultiFactor()
            && $context->authenticationStrength()->value < AuthenticationStrength::MultiFactor->value) {
            return false;
        }

        $deviceReference = $context->deviceReference();

        if ($deviceReference === null) {
            return false;
        }

        $existing = $this->trustedDeviceRepository->findActiveForIdentityAndDevice(
            $context->reference,
            $deviceReference,
        );

        if ($existing === null && count($this->trustedDeviceRepository->listForIdentity($context->reference)) >= $this->trustedDevicesMaxDevices()) {
            return false;
        }

        $summary = $existing ?? $this->trustedDeviceFromContext($context, $label);

        if ($summary === null) {
            return false;
        }

        $resolvedLabel = is_string($label) && trim($label) !== ''
            ? trim($label)
            : ($summary->label() ?? $this->contextStringAttribute($context, 'session_label'));

        $attributes = array_filter(array_merge(
            $summary->attributes,
            $this->trustedDeviceAttributesFromContext($context),
            [
                'label' => $resolvedLabel,
                'trust_state' => 'trusted',
            ],
        ), static fn (mixed $value): bool => $value !== null && $value !== '');

        $trustedDevice = new TrustedDevice(
            publicId: $summary->publicId,
            reference: $context->reference,
            deviceReference: $deviceReference,
            issuedAt: $existing?->issuedAt ?? time(),
            expiresAt: $this->trustedDeviceExpiresAt(),
            lastUsedAt: time(),
            attributes: $attributes,
        );

        $trustedDevice = $this->issueTrustedDeviceCredential($trustedDevice);
        $this->synchronizeCurrentSessionTrustState($context, 'trusted');

        return true;
    }

    public function forgetTrustedDevice(string $publicId): bool
    {
        $context = $this->context();
        $publicId = trim($publicId);

        if ($context === null || $publicId === '') {
            return false;
        }

        foreach ($this->trustedDeviceRepository->listForIdentity($context->reference) as $device) {
            if ($device->publicId->value !== $publicId) {
                continue;
            }

            if ($context->deviceReference() !== null && $context->deviceReference() !== $device->deviceReference) {
                $this->assertFreshAuthenticationForSensitiveTrustedDeviceOperation($context, 'trusted_device_revocation');
            }

            $this->trustedDeviceRepository->delete($publicId);

            if ($context->deviceReference() !== null && $context->deviceReference() === $device->deviceReference) {
                $this->queueTrustedDeviceLogoutCookie();
                $this->synchronizeCurrentSessionTrustState($context, 'unknown');
            }

            return true;
        }

        return false;
    }

    public function revokeDevice(string $deviceReference): bool
    {
        return $this->revokeDeviceInternal($deviceReference, true);
    }

    public function revokeOtherDevices(): int
    {
        $context = $this->context();

        if ($context === null) {
            return 0;
        }

        $deviceReferences = [];

        foreach ($this->devices() as $device) {
            if ($device->current) {
                continue;
            }

            $deviceReferences[$device->deviceReference] = true;
        }

        $count = count($deviceReferences);

        if ($count === 0) {
            return 0;
        }

        if ($this->bulkDeviceRevocationTouchesRemoteSessions($context, array_keys($deviceReferences))) {
            $this->assertFreshAuthenticationForSensitiveSessionOperation($context, 'device_revocation_bulk');
        }

        if ($this->bulkDeviceRevocationTouchesRemoteTrustedDevices($context, array_keys($deviceReferences))) {
            $this->assertFreshAuthenticationForSensitiveTrustedDeviceOperation($context, 'device_revocation_bulk');
        }

        foreach (array_keys($deviceReferences) as $deviceReference) {
            $this->revokeDeviceInternal($deviceReference, false);
        }

        return $count;
    }

    private function revokeDeviceInternal(string $deviceReference, bool $enforceFreshAuthentication): bool
    {
        $context = $this->context();
        $deviceReference = trim($deviceReference);

        if ($context === null || $deviceReference === '') {
            return false;
        }

        $sessions = $this->sessionsForDeviceReference($context, $deviceReference);
        $trustedDevices = $this->trustedDevicesForDeviceReference($context, $deviceReference);

        if ($sessions === [] && $trustedDevices === []) {
            return false;
        }

        $currentDeviceReference = $context->deviceReference();
        $targetsCurrentDevice = $currentDeviceReference !== null && $currentDeviceReference === $deviceReference;
        $touchesRemoteSessions = false;
        $touchesRemoteTrustedDevices = ! $targetsCurrentDevice && $trustedDevices !== [];
        $revokeCurrentSession = false;

        foreach ($sessions as $session) {
            if ($this->isCurrentSession($context, $session)) {
                $revokeCurrentSession = true;
                continue;
            }

            $touchesRemoteSessions = true;
        }

        if ($enforceFreshAuthentication && $touchesRemoteSessions) {
            $this->assertFreshAuthenticationForSensitiveSessionOperation($context, 'device_revocation');
        }

        if ($enforceFreshAuthentication && $touchesRemoteTrustedDevices) {
            $this->assertFreshAuthenticationForSensitiveTrustedDeviceOperation($context, 'device_revocation');
        }

        foreach ($sessions as $session) {
            $this->sessions->delete($session->id->value);
        }

        foreach ($trustedDevices as $trustedDevice) {
            $this->trustedDeviceRepository->delete($trustedDevice->publicId->value);
        }

        if ($targetsCurrentDevice && $trustedDevices !== []) {
            $this->queueTrustedDeviceLogoutCookie();
        }

        if ($revokeCurrentSession) {
            $this->clearCurrentAuthenticationStateAfterSessionRevocation();
        } elseif ($targetsCurrentDevice && $trustedDevices !== []) {
            $this->synchronizeCurrentSessionTrustState($context, 'unknown');
        }

        return true;
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

            if (! $this->isCurrentSession($context, $session)) {
                $this->assertFreshAuthenticationForSensitiveSessionOperation($context, 'session_revocation');
            }

            $this->sessions->delete($session->id->value);

            if ($this->activeSessionId() === $session->id->value) {
                $this->clearCurrentAuthenticationStateAfterSessionRevocation();
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
            $this->assertFreshAuthenticationForSensitiveSessionOperation($context, 'session_revocation_bulk');
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
    ): AuthenticationContext {
        $deviceReference = $this->deviceReference($context);
        $trustedDevice = $this->trustedDeviceValidationForContext($context)->device;
        $deviceTrustState = $trustedDevice !== null ? 'trusted' : 'unknown';

        return new AuthenticationContext(
            identity: $context->identity,
            reference: $context->reference instanceof IdentityReference
                ? $context->reference
                : new IdentityReference($context->identity->identifier(), $context->identity->type()),
            requestId: $context->requestId,
            method: $context->method,
            attributes: AuthenticationAssurance::enrichAttributes(
                array_merge(
                    $context->attributes,
                    $this->sessionMetadata(),
                    [
                        'authentication_fresh_at' => time(),
                        'session_device_reference' => $deviceReference,
                        'session_device_trust_state' => $deviceTrustState,
                        'trusted_device_credential_present' => $trustedDevice !== null,
                        'trusted_device_public_id' => $trustedDevice?->publicId->value,
                        'session_id' => $sessionId->value,
                        'session_public_id' => $publicId->value,
                    ],
                ),
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

    private function trustedDeviceCookieName(): string
    {
        $configured = $this->config->get('auth.trusted_devices.cookie', AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME);

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : AuthenticationHttpState::TRUSTED_DEVICE_COOKIE_NAME;
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

    private function queueTrustedDeviceLogoutCookie(): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::PENDING_TRUSTED_DEVICE_COOKIE_KEY,
            AuthenticationHttpState::clearTrustedDeviceCookie($this->trustedDeviceCookieName()),
        );
    }

    private function queueTrustedDeviceCookie(string $value): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::PENDING_TRUSTED_DEVICE_COOKIE_KEY,
            AuthenticationHttpState::trustedDeviceCookie(
                $value,
                $this->trustedDeviceCookieName(),
                $this->trustedDeviceLifetime(),
            ),
        );
    }

    private function rememberRecoveryFailureReason(?string $reason): void
    {
        $this->runtimeContext()->set(
            AuthenticationHttpState::RECOVERY_FAILURE_REASON_KEY,
            is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
        );
    }

    private function toSessionSummary(
        AuthenticationSession $session,
        bool $current,
        bool $requiresRemoteReauthentication = false,
        array $trustedDeviceReferences = [],
        ?string $currentTrustState = null,
    ): ?AuthenticationSessionSummary
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
            lastActivityAt: $this->timestampAttribute($session, 'session_last_activity_at'),
            current: $current,
            label: $session->label(),
            clientFamily: $this->stringAttribute($session, 'session_client_family'),
            clientPlatform: $this->stringAttribute($session, 'session_client_platform'),
            deviceKind: $this->stringAttribute($session, 'session_device_kind'),
            deviceReference: $this->stringAttribute($session, 'session_device_reference'),
            deviceTrustState: $current && $currentTrustState !== null
                ? $currentTrustState
                : $this->deviceTrustStateForSession($session, $trustedDeviceReferences),
            ipPrefix: $this->stringAttribute($session, 'session_ip_prefix'),
            canRevoke: true,
            requiresReauthentication: ! $current && $requiresRemoteReauthentication,
            revocationScope: $current ? 'current' : 'peer',
            revocationMode: $current ? 'direct' : ($requiresRemoteReauthentication ? 'fresh_auth_required' : 'direct'),
        );
    }

    private function toTrustedDeviceSummary(
        TrustedDevice $device,
        bool $current,
        bool $requiresRemoteReauthentication = false,
    ): TrustedDeviceSummary
    {
        return new TrustedDeviceSummary(
            publicId: $device->publicId->value,
            deviceReference: $device->deviceReference,
            trustState: 'trusted',
            issuedAt: $device->issuedAt,
            expiresAt: $device->expiresAt,
            lastUsedAt: $device->lastUsedAt,
            current: $current,
            canForget: true,
            requiresReauthentication: ! $current && $requiresRemoteReauthentication,
            revocationScope: $current ? 'current' : 'peer',
            revocationMode: $current ? 'direct' : ($requiresRemoteReauthentication ? 'fresh_auth_required' : 'direct'),
            label: $this->trustedDeviceStringAttribute($device, 'label'),
            clientFamily: $this->trustedDeviceStringAttribute($device, 'client_family'),
            clientPlatform: $this->trustedDeviceStringAttribute($device, 'client_platform'),
            deviceKind: $this->trustedDeviceStringAttribute($device, 'device_kind'),
        );
    }

    private function isCurrentSession(AuthenticationContext $context, AuthenticationSession $session): bool
    {
        $activeSessionId = $this->activeSessionId();

        if ($activeSessionId !== null && $activeSessionId === $session->id->value) {
            return true;
        }

        $contextSessionId = $context->attribute('session_id');

        return is_string($contextSessionId)
            && trim($contextSessionId) !== ''
            && trim($contextSessionId) === $session->id->value;
    }

    private function assertFreshAuthenticationForSensitiveSessionOperation(
        AuthenticationContext $context,
        string $operation,
    ): void {
        if (! $this->freshAuthenticationRequiredForRemoteSessionRevocation()) {
            return;
        }

        $freshAt = $context->freshAuthenticationAt();
        $window = $this->freshAuthenticationWindowSeconds();

        if ($freshAt === null || (time() - $freshAt) > $window) {
            throw new FreshAuthenticationRequiredException(
                operation: $operation,
                freshWindowSeconds: $window,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionMetadata(): array
    {
        $request = $this->runtimeContext()->request();
        $clientFamily = $this->clientFamily($request);
        $clientPlatform = $this->clientPlatform($request);
        $deviceKind = $this->deviceKind($request);
        $ipPrefix = $this->ipPrefix($request);
        $label = $this->sessionLabel($clientFamily, $clientPlatform, $ipPrefix);

        return array_filter([
            'session_client_family' => $clientFamily,
            'session_client_platform' => $clientPlatform,
            'session_device_kind' => $deviceKind,
            'session_ip_prefix' => $ipPrefix,
            'session_label' => $label,
            'session_last_activity_at' => time(),
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function touchRecoveredSession(
        string $sessionId,
        ?TrustedDevice $trustedDevice = null,
        bool $clearTrustState = false,
    ): void
    {
        $existing = $this->sessions->find($sessionId);

        if ($existing === null) {
            return;
        }

        $attributes = array_merge($existing->attributes, $this->sessionMetadata());
        $attributes['session_device_reference'] = $this->stableAttribute(
            $existing->attributes,
            $attributes,
            'session_device_reference',
        );
        $attributes['session_device_trust_state'] = $clearTrustState
            ? 'unknown'
            : $this->stableAttribute(
                $existing->attributes,
                $attributes,
                'session_device_trust_state',
                $trustedDevice !== null ? 'trusted' : 'unknown',
            );
        $attributes['trusted_device_credential_present'] = $trustedDevice !== null;
        $attributes['trusted_device_public_id'] = $clearTrustState ? null : $trustedDevice?->publicId->value;

        $this->sessions->touch(new AuthenticationSession(
            id: $existing->id,
            identity: $existing->identity,
            reference: $existing->reference,
            method: $existing->method,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            attributes: $attributes,
        ));
    }

    private function touchTrustedDeviceForContext(AuthenticationContext $context, ?TrustedDevice $trustedDevice = null): void
    {
        if ($trustedDevice === null) {
            return;
        }

        $this->trustedDeviceRepository->touch(new TrustedDevice(
            publicId: $trustedDevice->publicId,
            reference: $trustedDevice->reference,
            deviceReference: $trustedDevice->deviceReference,
            issuedAt: $trustedDevice->issuedAt,
            expiresAt: $trustedDevice->expiresAt,
            lastUsedAt: time(),
            attributes: array_merge(
                $trustedDevice->attributes,
                $this->trustedDeviceAttributesFromContext($context),
                ['trust_state' => 'trusted'],
            ),
        ));
    }

    private function validatedTrustedDeviceForContext(AuthenticationContext $context): ?TrustedDevice
    {
        $validation = $this->trustedDeviceValidationForContext($context);

        if (! $validation->isValid()) {
            return null;
        }

        return $validation->device;
    }

    private function trustedDeviceValidationForContext(AuthenticationContext $context): TrustedDeviceCredentialValidationResult
    {
        $validation = $this->trustedDeviceCredentialValidator()->validate(
            $this->trustedDeviceCredentialFromRequest(),
            $context->reference,
            $context->deviceReference() ?? $this->deviceReference($context),
        );

        if ($validation->invalid) {
            $this->queueTrustedDeviceLogoutCookie();
        }

        return $validation;
    }

    private function withTrustedDeviceContext(
        AuthenticationContext $context,
        ?TrustedDevice $trustedDevice,
        bool $clearTrustState = false,
    ): AuthenticationContext
    {
        return new AuthenticationContext(
            identity: $context->identity,
            reference: $context->reference,
            requestId: $context->requestId,
            method: $context->method,
            attributes: array_merge($context->attributes, [
                'session_device_trust_state' => $trustedDevice !== null
                    ? 'trusted'
                    : ($clearTrustState ? 'unknown' : ($context->attributes['session_device_trust_state'] ?? 'unknown')),
                'trusted_device_credential_present' => $trustedDevice !== null,
                'trusted_device_public_id' => $clearTrustState ? null : $trustedDevice?->publicId->value,
            ]),
        );
    }

    private function issueTrustedDeviceCredential(TrustedDevice $device): TrustedDevice
    {
        $secret = bin2hex(random_bytes(32));
        $issuedAt = time();
        $currentCredentialHash = $device->attributes['credential_hash'] ?? null;
        $trustedDevice = new TrustedDevice(
            publicId: $device->publicId,
            reference: $device->reference,
            deviceReference: $device->deviceReference,
            issuedAt: $device->issuedAt,
            expiresAt: $device->expiresAt,
            lastUsedAt: $device->lastUsedAt,
            attributes: array_merge($device->attributes, [
                'credential_hash' => $this->hashTrustedDeviceSecret($secret),
                'previous_credential_hash' => is_string($currentCredentialHash) && trim($currentCredentialHash) !== ''
                    ? trim($currentCredentialHash)
                    : null,
                'credential_issued_at' => $issuedAt,
                'credential_rotated_at' => $issuedAt,
                'trust_state' => 'trusted',
            ]),
        );

        $this->trustedDeviceRepository->save($trustedDevice);
        $this->queueTrustedDeviceCookie($trustedDevice->publicId->value . '.' . $secret);

        return $trustedDevice;
    }

    private function clientFamily(\Quantum\Http\Request $request): ?string
    {
        $userAgent = trim((string) $request->header('User-Agent', ''));

        if ($userAgent === '') {
            return null;
        }

        $normalized = strtolower($userAgent);

        return match (true) {
            str_contains($normalized, 'firefox') => 'Firefox',
            str_contains($normalized, 'edg/') => 'Edge',
            str_contains($normalized, 'chrome') => 'Chrome',
            str_contains($normalized, 'safari') && ! str_contains($normalized, 'chrome') => 'Safari',
            str_contains($normalized, 'curl') => 'curl',
            str_contains($normalized, 'postman') => 'Postman',
            str_contains($normalized, 'insomnia') => 'Insomnia',
            default => 'Unknown client',
        };
    }

    private function clientPlatform(\Quantum\Http\Request $request): ?string
    {
        $userAgent = trim((string) $request->header('User-Agent', ''));

        if ($userAgent === '') {
            return null;
        }

        $normalized = strtolower($userAgent);

        return match (true) {
            str_contains($normalized, 'windows') => 'Windows',
            str_contains($normalized, 'android') => 'Android',
            str_contains($normalized, 'iphone'),
            str_contains($normalized, 'ipad'),
            str_contains($normalized, 'ios') => 'iOS',
            str_contains($normalized, 'mac os'),
            str_contains($normalized, 'macintosh') => 'macOS',
            str_contains($normalized, 'linux') => 'Linux',
            default => null,
        };
    }

    private function deviceKind(\Quantum\Http\Request $request): ?string
    {
        $userAgent = trim((string) $request->header('User-Agent', ''));

        if ($userAgent === '') {
            return null;
        }

        $normalized = strtolower($userAgent);

        return match (true) {
            str_contains($normalized, 'ipad'),
            str_contains($normalized, 'tablet') => 'tablet',
            str_contains($normalized, 'iphone'),
            str_contains($normalized, 'android') && str_contains($normalized, 'mobile'),
            str_contains($normalized, 'mobile') => 'mobile',
            str_contains($normalized, 'postman'),
            str_contains($normalized, 'curl'),
            str_contains($normalized, 'insomnia') => 'scripted',
            default => 'desktop',
        };
    }

    private function ipPrefix(\Quantum\Http\Request $request): ?string
    {
        $candidates = [
            $request->header('X-Forwarded-For'),
            $request->server('REMOTE_ADDR'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $raw = trim(explode(',', $candidate)[0]);

            if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $raw);

                return count($parts) === 4
                    ? sprintf('%s.%s.%s.x', $parts[0], $parts[1], $parts[2])
                    : null;
            }

            if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $parts = explode(':', $raw);
                $parts = array_pad($parts, 8, '');

                return strtolower(implode(':', array_slice($parts, 0, 4))) . '::*';
            }
        }

        return null;
    }

    private function sessionLabel(?string $clientFamily, ?string $clientPlatform, ?string $ipPrefix): ?string
    {
        $base = null;

        if ($clientFamily !== null && $clientPlatform !== null) {
            $base = sprintf('%s on %s', $clientFamily, $clientPlatform);
        } elseif ($clientFamily !== null) {
            $base = $clientFamily;
        } elseif ($clientPlatform !== null) {
            $base = $clientPlatform;
        }

        if ($base === null && $ipPrefix === null) {
            return null;
        }

        if ($base !== null && $ipPrefix !== null) {
            return sprintf('%s from %s', $base, $ipPrefix);
        }

        return $base ?? $ipPrefix;
    }

    private function deviceReference(AuthenticationContext $context): ?string
    {
        $request = $this->runtimeContext()->request();
        $fingerprintParts = array_filter([
            strtolower(trim((string) $request->host())),
            strtolower(trim((string) $request->header('User-Agent', ''))),
            strtolower(trim((string) $request->header('Accept-Language', ''))),
            strtolower((string) ($context->reference->identifier->value ?? '')),
            strtolower($context->reference->type),
        ], static fn(string $value): bool => $value !== '');

        if ($fingerprintParts === []) {
            return null;
        }

        $hash = hash_hmac(
            'sha256',
            implode('|', $fingerprintParts),
            $this->deviceReferenceSalt(),
        );

        return 'devref_' . substr($hash, 0, 20);
    }

    private function trustedDeviceReferences(IdentityReference $reference): array
    {
        $references = [];

        foreach ($this->trustedDeviceRepository->listForIdentity($reference) as $device) {
            $references[$device->deviceReference] = true;
        }

        return $references;
    }

    private function deviceReferenceSalt(): string
    {
        $configured = $this->config->get('auth.session.device.reference_salt');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = $this->config->get('app.key');

        if (is_string($appKey) && trim($appKey) !== '') {
            return trim($appKey);
        }

        return 'voltstack-auth-device-reference';
    }

    private function freshAuthenticationRequiredForRemoteSessionRevocation(): bool
    {
        return (bool) $this->config->get('auth.session.management.require_fresh_auth_for_remote_revocation', true);
    }

    private function freshAuthenticationWindowSeconds(): int
    {
        $configured = $this->config->get('auth.session.management.fresh_auth_window', 300);

        if (is_int($configured)) {
            return max(0, $configured);
        }

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return 300;
    }

    private function assertFreshAuthenticationForSensitiveTrustedDeviceOperation(
        AuthenticationContext $context,
        string $operation,
    ): void {
        if (! $this->freshAuthenticationRequiredForRemoteTrustedDeviceRevocation()) {
            return;
        }

        $freshAt = $context->freshAuthenticationAt();
        $window = $this->freshAuthenticationWindowSecondsForTrustedDevices();

        if ($freshAt === null || (time() - $freshAt) > $window) {
            throw new FreshAuthenticationRequiredException(
                operation: $operation,
                freshWindowSeconds: $window,
            );
        }
    }

    private function stringAttribute(AuthenticationSession $session, string $key): ?string
    {
        $value = $session->attributes[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function timestampAttribute(AuthenticationSession $session, string $key): ?int
    {
        $value = $session->attributes[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function contextStringAttribute(AuthenticationContext $context, string $key): ?string
    {
        $value = $context->attribute($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function requiresFreshAuthenticationHintForCurrentContext(AuthenticationContext $context): bool
    {
        if (! $this->freshAuthenticationRequiredForRemoteSessionRevocation()) {
            return false;
        }

        $freshAt = $context->freshAuthenticationAt();

        return $freshAt === null || (time() - $freshAt) > $this->freshAuthenticationWindowSeconds();
    }

    private function requiresFreshAuthenticationHintForCurrentTrustedDeviceContext(AuthenticationContext $context): bool
    {
        if (! $this->freshAuthenticationRequiredForRemoteTrustedDeviceRevocation()) {
            return false;
        }

        $freshAt = $context->freshAuthenticationAt();

        return $freshAt === null || (time() - $freshAt) > $this->freshAuthenticationWindowSecondsForTrustedDevices();
    }

    private function trustedDevicesRequireMultiFactor(): bool
    {
        return (bool) $this->config->get('auth.trusted_devices.require_multi_factor', true);
    }

    private function trustedDevicesMaxDevices(): int
    {
        $configured = $this->config->get('auth.trusted_devices.max_devices', 10);

        if (is_int($configured)) {
            return max(1, $configured);
        }

        if (is_numeric($configured)) {
            return max(1, (int) $configured);
        }

        return 10;
    }

    private function trustedDeviceLifetime(): int
    {
        $configured = $this->config->get('auth.trusted_devices.lifetime', 2592000);

        if (is_int($configured)) {
            return max(0, $configured);
        }

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return 2592000;
    }

    private function freshAuthenticationRequiredForRemoteTrustedDeviceRevocation(): bool
    {
        return (bool) $this->config->get(
            'auth.trusted_devices.management.require_fresh_auth_for_remote_revocation',
            $this->freshAuthenticationRequiredForRemoteSessionRevocation(),
        );
    }

    private function freshAuthenticationWindowSecondsForTrustedDevices(): int
    {
        $configured = $this->config->get(
            'auth.trusted_devices.management.fresh_auth_window',
            $this->freshAuthenticationWindowSeconds(),
        );

        if (is_int($configured)) {
            return max(0, $configured);
        }

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return $this->freshAuthenticationWindowSeconds();
    }

    private function trustedDeviceExpiresAt(): ?int
    {
        $configured = $this->config->get('auth.trusted_devices.lifetime', 2592000);

        if (is_int($configured)) {
            return time() + max(0, $configured);
        }

        if (is_numeric($configured)) {
            return time() + max(0, (int) $configured);
        }

        return time() + 2592000;
    }

    /**
     * @return array<string, mixed>
     */
    private function trustedDeviceAttributesFromContext(AuthenticationContext $context): array
    {
        return array_filter([
            'label' => $this->contextStringAttribute($context, 'session_label'),
            'client_family' => $this->contextStringAttribute($context, 'session_client_family'),
            'client_platform' => $this->contextStringAttribute($context, 'session_client_platform'),
            'device_kind' => $this->contextStringAttribute($context, 'session_device_kind'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function trustedDeviceFromContext(AuthenticationContext $context, ?string $label = null): ?TrustedDevice
    {
        $deviceReference = $context->deviceReference();

        if ($deviceReference === null) {
            return null;
        }

        $attributes = $this->trustedDeviceAttributesFromContext($context);

        if (is_string($label) && trim($label) !== '') {
            $attributes['label'] = trim($label);
        }

        $attributes['trust_state'] = 'trusted';

        return new TrustedDevice(
            publicId: TrustedDevicePublicId::generate(),
            reference: $context->reference,
            deviceReference: $deviceReference,
            issuedAt: time(),
            expiresAt: $this->trustedDeviceExpiresAt(),
            lastUsedAt: time(),
            attributes: $attributes,
        );
    }

    private function synchronizeCurrentSessionTrustState(AuthenticationContext $context, string $trustState): void
    {
        $trustedDevicePublicId = $trustState === 'trusted'
            ? ($context->trustedDevicePublicId() ?? $this->validatedTrustedDeviceForContext($context)?->publicId->value)
            : null;
        $attributes = array_merge($context->attributes, [
            'session_device_trust_state' => $trustState,
            'trusted_device_credential_present' => $trustState === 'trusted',
            'trusted_device_public_id' => $trustedDevicePublicId,
        ]);

        $this->accessor->put(new AuthenticationContext(
            identity: $context->identity,
            reference: $context->reference,
            requestId: $context->requestId,
            method: $context->method,
            attributes: $attributes,
        ));

        $sessionId = $context->attribute('session_id');

        if (! is_string($sessionId) || trim($sessionId) === '') {
            return;
        }

        $existing = $this->sessions->find(trim($sessionId));

        if ($existing === null) {
            return;
        }

        $sessionAttributes = array_merge($existing->attributes, [
            'session_device_trust_state' => $trustState,
            'trusted_device_credential_present' => $trustState === 'trusted',
            'trusted_device_public_id' => $trustedDevicePublicId,
        ]);

        $this->sessions->touch(new AuthenticationSession(
            id: $existing->id,
            identity: $existing->identity,
            reference: $existing->reference,
            method: $existing->method,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            attributes: $sessionAttributes,
        ));
    }

    private function deviceTrustStateForSession(AuthenticationSession $session, array $trustedDeviceReferences): string
    {
        $deviceReference = $this->stringAttribute($session, 'session_device_reference');

        if ($deviceReference !== null && isset($trustedDeviceReferences[$deviceReference])) {
            return 'trusted';
        }

        return $this->stringAttribute($session, 'session_device_trust_state') ?? 'unknown';
    }

    private function trustedDeviceStringAttribute(TrustedDevice $device, string $key): ?string
    {
        $value = $device->attributes[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @param array{
     *   device_reference: string,
     *   trust_state: string,
     *   session_count: int,
     *   current_session_count: int,
     *   current: bool,
     *   has_trusted_device: bool,
     *   trusted_device_public_id: ?string,
     *   session_public_ids: list<string>,
     *   last_seen_at: ?int,
     *   requires_reauthentication: bool,
     *   label: ?string,
     *   client_family: ?string,
     *   client_platform: ?string,
     *   device_kind: ?string
     * } $entry
     */
    private function toDeviceInventorySummary(array $entry): DeviceInventorySummary
    {
        $scope = $this->deviceInventoryScope(
            (bool) $entry['current'],
            (int) $entry['session_count'],
            (int) $entry['current_session_count'],
            (bool) $entry['has_trusted_device'],
        );
        $requiresReauthentication = (bool) $entry['requires_reauthentication'];

        return new DeviceInventorySummary(
            deviceReference: $entry['device_reference'],
            trustState: $entry['trust_state'],
            sessionCount: $entry['session_count'],
            currentSessionCount: $entry['current_session_count'],
            current: $entry['current'],
            hasTrustedDevice: $entry['has_trusted_device'],
            trustedDevicePublicId: $entry['trusted_device_public_id'],
            sessionPublicIds: $entry['session_public_ids'],
            lastSeenAt: $entry['last_seen_at'],
            canRevokeSessions: $entry['session_count'] > 0,
            canForgetTrustedDevice: $entry['has_trusted_device'],
            requiresReauthentication: $requiresReauthentication,
            managementScope: $scope,
            managementMode: $requiresReauthentication ? 'fresh_auth_required' : 'direct',
            label: $entry['label'],
            clientFamily: $entry['client_family'],
            clientPlatform: $entry['client_platform'],
            deviceKind: $entry['device_kind'],
        );
    }

    private function deviceInventoryScope(
        bool $current,
        int $sessionCount,
        int $currentSessionCount,
        bool $hasTrustedDevice,
    ): string {
        $hasRemoteSessions = $sessionCount > $currentSessionCount;

        if ($current && $hasRemoteSessions) {
            return 'mixed';
        }

        return $current ? 'current' : 'peer';
    }

    private function preferredInventoryValue(?string $current, ?string $candidate): ?string
    {
        if (is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        return is_string($candidate) && trim($candidate) !== ''
            ? trim($candidate)
            : null;
    }

    /**
     * @return list<AuthenticationSession>
     */
    private function sessionsForDeviceReference(AuthenticationContext $context, string $deviceReference): array
    {
        $matches = [];

        foreach ($this->sessions->listForIdentity($context->identity) as $session) {
            if ($this->stringAttribute($session, 'session_device_reference') !== $deviceReference) {
                continue;
            }

            $matches[] = $session;
        }

        return $matches;
    }

    /**
     * @return list<TrustedDevice>
     */
    private function trustedDevicesForDeviceReference(AuthenticationContext $context, string $deviceReference): array
    {
        $matches = [];

        foreach ($this->trustedDeviceRepository->listForIdentity($context->reference) as $device) {
            if ($device->deviceReference !== $deviceReference) {
                continue;
            }

            $matches[] = $device;
        }

        return $matches;
    }

    private function clearCurrentAuthenticationStateAfterSessionRevocation(): void
    {
        $this->accessor->clear();
        $this->rememberRecoveryFailureReason(null);
        $this->runtimeContext()->set(AuthenticationHttpState::ACTIVE_SESSION_ID_KEY, null);
        $this->queueLogoutCookie();
        $this->runtimeContext()->set(AuthenticationHttpState::PENDING_SESSION_HEADER_KEY, 'cleared');
    }

    /**
     * @param list<string> $deviceReferences
     */
    private function bulkDeviceRevocationTouchesRemoteSessions(
        AuthenticationContext $context,
        array $deviceReferences,
    ): bool {
        foreach ($deviceReferences as $deviceReference) {
            $sessions = $this->sessionsForDeviceReference($context, $deviceReference);

            foreach ($sessions as $session) {
                if (! $this->isCurrentSession($context, $session)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $deviceReferences
     */
    private function bulkDeviceRevocationTouchesRemoteTrustedDevices(
        AuthenticationContext $context,
        array $deviceReferences,
    ): bool {
        foreach ($deviceReferences as $deviceReference) {
            $trustedDevices = $this->trustedDevicesForDeviceReference($context, $deviceReference);

            if ($trustedDevices !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $candidate
     */
    private function stableAttribute(array $existing, array $candidate, string $key, ?string $default = null): ?string
    {
        $current = $existing[$key] ?? null;

        if (is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        $next = $candidate[$key] ?? $default;

        return is_string($next) && trim($next) !== ''
            ? trim($next)
            : $default;
    }

    private function trustedDeviceCredentialFromRequest(): ?string
    {
        $credential = $this->runtimeContext()->request()->cookie($this->trustedDeviceCookieName());

        return is_string($credential) && trim($credential) !== ''
            ? trim(rawurldecode($credential))
            : null;
    }

    private function hashTrustedDeviceSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->deviceReferenceSalt());
    }

    private function trustedDeviceCredentialValidator(): TrustedDeviceCredentialValidator
    {
        return new TrustedDeviceCredentialValidator($this->trustedDeviceRepository, $this->config);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function rotateTrustedDeviceAfterChallengeReduction(array $metadata): void
    {
        if (! (bool) ($metadata['trusted_device_rotate'] ?? false)) {
            return;
        }

        $publicId = $metadata['trusted_device_public_id'] ?? null;

        if (! is_string($publicId) || trim($publicId) === '') {
            return;
        }

        $device = $this->trustedDeviceRepository->find(trim($publicId));

        if ($device === null || $device->isExpired()) {
            $this->queueTrustedDeviceLogoutCookie();

            return;
        }

        $this->issueTrustedDeviceCredential($device);
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
