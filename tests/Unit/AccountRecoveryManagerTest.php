<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\PasswordLifecycleAwareProviderInterface;
use Quantum\Auth\Contracts\RecoveryCodeStoreInterface;
use Quantum\Auth\Contracts\RecoveryManagerInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Exceptions\RecoveryEvidenceRejectedException;
use Quantum\Auth\Exceptions\RecoveryPasswordReuseException;
use Quantum\Auth\Exceptions\RecoveryTokenInvalidException;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Recovery\AdminRecoveryEvidenceVerifier;
use Quantum\Auth\Recovery\MfaRecoveryEvidenceVerifier;
use Quantum\Auth\Recovery\PasskeyRecoveryEvidenceVerifier;
use Quantum\Auth\Recovery\RecoveryAuditEvent;
use Quantum\Auth\Recovery\RecoveryCode;
use Quantum\Auth\Recovery\RecoveryContinuationRequest;
use Quantum\Auth\Recovery\RecoveryEvidence;
use Quantum\Auth\Recovery\RecoveryEvidenceKind;
use Quantum\Auth\Recovery\RecoveryManager;
use Quantum\Auth\Recovery\RecoveryPurpose;
use Quantum\Auth\Recovery\RecoveryRequest;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;
use Quantum\Auth\Tokens\TokenId;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AccountRecoveryManagerTest extends TestCase
{
    public function test_recovery_manager_resets_password_and_revokes_sessions_and_tokens(): void
    {
        $basePath = $this->createBasePath();
        $notificationsPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-notifications.jsonl';
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-audit.jsonl';

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.passkeys.enabled', true);
        $config->set('auth.recovery.notifications.driver', 'file');
        $config->set('auth.recovery.notifications.storage_path', $notificationsPath);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7101,
                'identifier' => 'recovery@example.com',
                'password_hash' => password_hash('old-secret-123', PASSWORD_DEFAULT),
                'password_rotation_history' => [
                    password_hash('very-old-secret-123', PASSWORD_DEFAULT),
                ],
                'failed_attempts' => 4,
                'lockout_until' => time() + 600,
                'security_state' => 'locked',
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $identityProvider = $app->make(IdentityProviderInterface::class);
        $lifecycle = $app->make(PasswordLifecycleAwareProviderInterface::class);
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $tokens = $app->make(OpaqueTokenRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $passkeys = $app->make(PasskeyCredentialStoreInterface::class);

        $identity = $identityProvider->findByIdentifier('recovery@example.com');
        self::assertNotNull($identity);

        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'recovery@example.com',
            transport: 'unit',
        ));

        self::assertTrue($start->accepted);
        self::assertTrue($start->issued);
        self::assertNotNull($start->token);
        self::assertTrue((bool) ($start->metadata['notification_dispatched'] ?? false));

        $session = new AuthenticationSession(
            id: new AuthenticationSessionId('sess-recovery-1'),
            identity: $identity,
            reference: new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type()),
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 3600,
            attributes: ['session_public_id' => 'spub-recovery-1'],
        );
        $sessions->save($session);

        $accessId = new TokenId('access-recovery-1');
        $refreshId = new TokenId('refresh-recovery-1');
        $reference = new IdentityReference($identity->identifier(), $identity->type());
        $tokens->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 3600,
            refreshTokenId: $refreshId,
        ));
        $tokens->saveRefreshToken(new OpaqueRefreshToken(
            id: $refreshId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 7200,
            accessTokenId: $accessId,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_recovery_1'),
            reference: $reference,
            deviceReference: 'device-recovery-1',
            issuedAt: time(),
            expiresAt: time() + 7200,
        ));
        self::assertInstanceOf(PasskeyCredentialStoreInterface::class, $passkeys);
        $passkeys->save(new PasskeyCredentialRecord(
            credentialId: 'pk-recovery-1',
            credentialPublicKey: 'pubkey-1',
            userHandle: 'recovery@example.com',
            rpId: 'localhost',
            createdAt: time(),
        ));

        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'N3w-secret-456!',
            transport: 'unit',
        ));

        self::assertTrue($result->completed);
        self::assertSame('7101', $result->identityId);
        self::assertSame(1, $result->sessionsRevoked);
        self::assertSame(2, $result->tokensRevoked);
        self::assertSame(1, $result->trustedDevicesRevoked);
        self::assertSame(1, $result->passkeysRevoked);
        self::assertTrue((bool) ($result->metadata['notification_dispatched'] ?? false));
        self::assertCount(0, $sessions->listForIdentity($identity));
        self::assertTrue($tokens->findAccessToken('access-recovery-1')?->revoked ?? false);
        self::assertTrue($tokens->findRefreshToken('refresh-recovery-1')?->revoked ?? false);
        self::assertCount(0, $trustedDevices->listForIdentity($reference));
        self::assertSame([], $passkeys->listForUserHandle('recovery@example.com'));
        self::assertTrue(password_verify(
            'N3w-secret-456!',
            (string) $app->make(ConfigRepository::class)->get('auth.providers.local.identities.0.password_hash'),
        ));

        $metadata = $lifecycle->passwordLifecycleMetadataFor($identity);
        self::assertSame(0, $metadata['failed_attempts']);
        self::assertNull($metadata['lockout_until']);
        self::assertSame('active', $metadata['security_state']);

        $notifications = $this->readJsonl($notificationsPath);
        self::assertCount(2, $notifications);
        self::assertSame('password_reset_requested', $notifications[0]['type'] ?? null);
        self::assertSame('password_reset_completed', $notifications[1]['type'] ?? null);
        self::assertSame('recovery@example.com', $notifications[0]['destination'] ?? null);
        self::assertSame($start->token, $notifications[0]['payload']['reset_token'] ?? null);
        self::assertSame(1, $notifications[1]['payload']['trusted_devices_revoked'] ?? null);
        self::assertSame(1, $notifications[1]['payload']['passkeys_revoked'] ?? null);

        $audit = $this->readJsonl($auditPath);
        self::assertNotEmpty($audit);
        $requestedEvent = current(array_values(array_filter(
            $audit,
            static fn(array $event): bool => ($event['action'] ?? null) === 'recovery_requested'
        )));
        self::assertNotEmpty($requestedEvent);
        self::assertSame('issued', $requestedEvent['result'] ?? null);

        $completedEvent = current(array_values(array_filter(
            $audit,
            static fn(array $event): bool => ($event['action'] ?? null) === 'recovery_completed'
        )));
        self::assertNotEmpty($completedEvent);
        self::assertSame(1, (int) ($completedEvent['payload']['sessions_revoked'] ?? 0));
        self::assertSame(2, (int) ($completedEvent['payload']['tokens_revoked'] ?? 0));
        self::assertSame(1, (int) ($completedEvent['payload']['trusted_devices_revoked'] ?? 0));
        self::assertSame(1, (int) ($completedEvent['payload']['passkeys_revoked'] ?? 0));
        self::assertSame('password_reset', $completedEvent['purpose'] ?? null);

        $this->expectException(RecoveryTokenInvalidException::class);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'Another-secret-789!',
            transport: 'unit',
        ));
    }

    public function test_recovery_manager_audits_token_replay_as_consumed_or_invalid(): void
    {
        $basePath = $this->createBasePath();
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-audit-replay.jsonl';

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.passkeys.enabled', true);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7102,
                'identifier' => 'replay@example.com',
                'password_hash' => password_hash('initial-secret-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'replay@example.com',
            transport: 'unit',
        ));

        self::assertNotNull($start->token);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'N3w-secret-456!',
            transport: 'unit',
        ));

        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start->token,
                newPassword: 'Anoth3r-secret-789!',
                transport: 'unit',
            ));
            self::fail('Expected replay to throw ' . RecoveryTokenInvalidException::class);
        } catch (RecoveryTokenInvalidException $e) {
            $auditAfterReplay = $this->readJsonl($auditPath);
            $replayDenial = array_values(array_filter(
                $auditAfterReplay,
                static fn(array $event): bool => in_array(
                    $event['action'] ?? null,
                    ['recovery_token_already_consumed', 'recovery_token_invalid'],
                    true,
                ),
            ));
            self::assertNotEmpty($replayDenial, 'Expected replay denial audit event.');
            return;
        }

        self::fail('Expected replay to throw ' . RecoveryTokenInvalidException::class);
    }

    public function test_recovery_manager_rejects_password_reuse_from_current_or_history(): void
    {
        $app = new Application($this->createBasePath());
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7201,
                'identifier' => 'history@example.com',
                'password_hash' => password_hash('current-secret-123', PASSWORD_DEFAULT),
                'password_rotation_history' => [
                    password_hash('old-secret-123', PASSWORD_DEFAULT),
                ],
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'history@example.com',
            transport: 'unit',
        ));

        self::assertNotNull($start->token);

        $this->expectException(RecoveryPasswordReuseException::class);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start->token,
            newPassword: 'old-secret-123',
            transport: 'unit',
        ));
    }

    private function createBasePath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-account-recovery-unit-' . uniqid('', true);

        if (! mkdir($concurrentDirectory = $path, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $path));
        }

        return $path;
    }

    public function test_passkey_bound_evidence_allows_recovery_when_configuration_requires_passkey(): void
    {
        $basePath = $this->createBasePath();
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-audit-passkey.jsonl';

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.passkeys.enabled', true);
        $config->set('auth.passkeys.store.driver', 'file');
        $config->set('auth.recovery.evidence.required_kinds', [RecoveryEvidenceKind::PasskeyAssertion]);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7301,
                'identifier' => 'passkey-user@example.com',
                'password_hash' => password_hash('pw-before-123', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $identity = $app->make(IdentityProviderInterface::class)->findByIdentifier('passkey-user@example.com');
        self::assertNotNull($identity);

        $passkeyStore = $app->make(PasskeyCredentialStoreInterface::class);
        $passkeyStore->save(new PasskeyCredentialRecord(
            credentialId: 'pk-recovery-1',
            credentialPublicKey: 'pk-pub-recovery-1',
            userHandle: (string) $identity->identifier(),
            rpId: 'localhost',
            createdAt: time(),
        ));

        /** @var RecoveryManagerInterface $manager */
        $manager = $app->make(RecoveryManagerInterface::class);

        // Without passkey evidence, continuation must fail as evidence rejected.
        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'passkey-user@example.com',
            transport: 'unit',
        ));
        self::assertNotNull($start->token);

        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start->token,
                newPassword: 'New-pass-901!',
                transport: 'unit',
            ));
            self::fail('Expected missing passkey evidence to throw ' . RecoveryEvidenceRejectedException::class);
        } catch (RecoveryEvidenceRejectedException $e) {
            self::assertSame('recovery.evidence.rejected', $e->reasonCode);
            $denials = array_values(array_filter(
                is_array($e->metadata['evidence_results'] ?? null) ? $e->metadata['evidence_results'] : [],
                static fn (array $r): bool => ($r['kind'] ?? null) === RecoveryEvidenceKind::PasskeyAssertion
                    && ! ($r['skip'] ?? false)
                    && ! ($r['passed'] ?? false),
            ));
            self::assertNotEmpty($denials);
        }

        // With correct credential bound passkey evidence, continuation succeeds.
        $start2 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'passkey-user@example.com',
            transport: 'unit',
        ));
        self::assertNotNull($start2->token);

        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start2->token,
            newPassword: 'New-pass-902!',
            transport: 'unit',
            attributes: [
                'recovery_evidences' => [
                    new RecoveryEvidence(RecoveryEvidenceKind::PasskeyAssertion, 'pk-recovery-1'),
                ],
            ],
        ));
        self::assertTrue($result->completed);

        // With wrong credential, continuation fails with evidence rejection.
        $start3 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'passkey-user@example.com',
            transport: 'unit',
        ));
        self::assertNotNull($start3->token);

        $this->expectException(RecoveryEvidenceRejectedException::class);

        $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start3->token,
            newPassword: 'New-pass-903!',
            transport: 'unit',
            attributes: [
                'recovery_evidences' => [
                    ['kind' => RecoveryEvidenceKind::PasskeyAssertion, 'value' => 'pk-unknown-credential'],
                ],
            ],
        ));
    }

    public function test_mfa_evidence_required_fails_without_code_and_passes_with_valid_code(): void
    {
        $basePath = $this->createBasePath();
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-audit-mfa.jsonl';

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.recovery.evidence.required_kinds', [RecoveryEvidenceKind::MfaTotpCode]);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.mfa.totp.enabled', true);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7401,
                'identifier' => 'mfa-user@example.com',
                'password_hash' => password_hash('pw-pre-mfa', PASSWORD_DEFAULT),
                'totp_secret' => 'JBSWY3DPEHPK3PXP',
                'type' => 'user',
            ],
        ]);

        // Build recovery manager with MFA verifier.
        $identities = $app->make(IdentityProviderInterface::class);
        $verifiers = [new MfaRecoveryEvidenceVerifier($identities)];
        $manager = new RecoveryManager(
            identities: $identities,
            passwordPolicy: $app->make(\Quantum\Auth\Contracts\PasswordPolicyInterface::class),
            mutableIdentities: $app->make(\Quantum\Auth\Contracts\MutableIdentityProviderInterface::class),
            passwordRehashing: $app->make(\Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface::class),
            tokens: $app->make(\Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface::class),
            sessions: $app->make(AuthenticationSessionRepositoryInterface::class),
            opaqueTokens: $app->make(OpaqueTokenRepositoryInterface::class),
            config: $config,
            trustedDevices: $app->make(TrustedDeviceRepositoryInterface::class),
            notifications: $app->make(\Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface::class),
            audit: $app->make(\Quantum\Auth\Contracts\RecoveryAuditLoggerInterface::class),
            evidenceVerifiers: $verifiers,
            passkeys: $app->make(PasskeyCredentialStoreInterface::class),
            passwordLifecycle: $app->make(PasswordLifecycleAwareProviderInterface::class),
            governance: $app->make(\Quantum\Auth\Contracts\DistributedPasswordGovernanceProviderInterface::class),
        );

        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'mfa-user@example.com',
            transport: 'unit',
        ));
        self::assertNotNull($start->token);

        // Without MFA evidence the continuation is rejected because required.
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start->token,
                newPassword: 'After-mfa-123!',
                transport: 'unit',
            ));
            self::fail('Expected MFA required without evidence to throw ' . RecoveryEvidenceRejectedException::class);
        } catch (RecoveryEvidenceRejectedException) {
            // expected
        }

        // Generate valid OTP from known totp_secret and submit it.
        $identity = $identities->findByIdentifier('mfa-user@example.com');
        self::assertNotNull($identity);
        $secret = (string) (($identity->attributes ?? [])['totp_secret'] ?? 'JBSWY3DPEHPK3PXP');
        $code = $this->generateTotpCode($secret);

        $start2 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'mfa-user@example.com',
            transport: 'unit',
        ));
        $good = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start2->token,
            newPassword: 'After-mfa-456!',
            transport: 'unit',
            attributes: [
                'recovery_evidences' => [
                    ['kind' => RecoveryEvidenceKind::MfaTotpCode, 'value' => $code],
                ],
            ],
        ));
        self::assertTrue($good->completed);

        // Audit trail preserves evidence denial plus subsequent success.
        $events = $this->readJsonl($auditPath);
        $evidenceDenial = current(array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === 'recovery_evidence_rejected',
        )));
        self::assertNotEmpty($evidenceDenial);
        $completed = current(array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === 'recovery_completed',
        )));
        self::assertNotEmpty($completed);
    }

    public function test_admin_initiated_recovery_requires_admin_reference_and_completes(): void
    {
        $basePath = $this->createBasePath();
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-audit-admin.jsonl';

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.recovery.evidence.admin.plain_whitelist', ['KNOWN-ADMIN-REF-123']);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7501,
                'identifier' => 'admin-reset@example.com',
                'password_hash' => password_hash('pw-pre-admin', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $verifiers = [
            new PasskeyRecoveryEvidenceVerifier($app->make(PasskeyCredentialStoreInterface::class)),
            new MfaRecoveryEvidenceVerifier($app->make(IdentityProviderInterface::class)),
            new AdminRecoveryEvidenceVerifier(plainTextWhitelist: ['KNOWN-ADMIN-REF-123']),
        ];

        $manager = new RecoveryManager(
            identities: $app->make(IdentityProviderInterface::class),
            passwordPolicy: $app->make(\Quantum\Auth\Contracts\PasswordPolicyInterface::class),
            mutableIdentities: $app->make(\Quantum\Auth\Contracts\MutableIdentityProviderInterface::class),
            passwordRehashing: $app->make(\Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface::class),
            tokens: $app->make(\Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface::class),
            sessions: $app->make(AuthenticationSessionRepositoryInterface::class),
            opaqueTokens: $app->make(OpaqueTokenRepositoryInterface::class),
            config: $config,
            trustedDevices: $app->make(TrustedDeviceRepositoryInterface::class),
            notifications: $app->make(\Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface::class),
            audit: $app->make(\Quantum\Auth\Contracts\RecoveryAuditLoggerInterface::class),
            evidenceVerifiers: $verifiers,
            passkeys: $app->make(PasskeyCredentialStoreInterface::class),
            passwordLifecycle: $app->make(PasswordLifecycleAwareProviderInterface::class),
            governance: $app->make(\Quantum\Auth\Contracts\DistributedPasswordGovernanceProviderInterface::class),
        );

        $identity = $app->make(IdentityProviderInterface::class)->findByIdentifier('admin-reset@example.com');
        self::assertNotNull($identity);

        $start = $manager->createRecoveryForIdentity(
            identity: $identity,
            purpose: RecoveryPurpose::AdminPasswordReset,
            transport: 'unit-admin',
        );
        self::assertTrue($start->issued);
        self::assertSame(RecoveryPurpose::AdminPasswordReset, $start->purpose);
        self::assertNotNull($start->token);

        // Without admin evidence, admin purpose recovery must reject because required by default.
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::AdminPasswordReset,
                token: $start->token,
                newPassword: 'Admin-pw-reset-123!',
                transport: 'unit-end-user',
            ));
            self::fail('Expected admin recovery without admin reference to throw ' . RecoveryEvidenceRejectedException::class);
        } catch (RecoveryEvidenceRejectedException $e) {
            $requiredMiss = array_values(array_filter(
                is_array($e->metadata['evidence_results'] ?? null) ? $e->metadata['evidence_results'] : [],
                static fn (array $r): bool => ($r['kind'] ?? null) === RecoveryEvidenceKind::AdminRecoveryReference
                    && ($r['reason_code'] ?? null) === 'recovery.evidence.missing_required',
            ));
            self::assertNotEmpty($requiredMiss);
        }

        // With whitelisted reference, continuation succeeds.
        $start2 = $manager->createRecoveryForIdentity(
            identity: $identity,
            purpose: RecoveryPurpose::AdminPasswordReset,
            transport: 'unit-admin',
        );
        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::AdminPasswordReset,
            token: $start2->token,
            newPassword: 'Admin-pw-reset-456!',
            transport: 'unit-end-user',
            attributes: [
                'recovery_evidences' => [
                    new RecoveryEvidence(RecoveryEvidenceKind::AdminRecoveryReference, 'KNOWN-ADMIN-REF-123'),
                ],
            ],
        ));
        self::assertTrue($result->completed);
        self::assertSame(RecoveryPurpose::AdminPasswordReset, $result->purpose);

        $events = $this->readJsonl($auditPath);
        $adminRequested = array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === 'recovery_requested'
                && ($e['purpose'] ?? null) === RecoveryPurpose::AdminPasswordReset->value,
        ));
        self::assertNotEmpty($adminRequested);
    }

    public function test_recovery_code_evidence_works_one_time_and_rotates_after_reset(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volt-ar-rc-' . uniqid('', true);
        mkdir($basePath, 0777, true);
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'audit.jsonl';
        $codesPath = $basePath . DIRECTORY_SEPARATOR . 'recovery-codes.jsonl';
        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.recovery.password_reset.rotate_recovery_codes', true);
        $config->set('auth.recovery.recovery_codes.driver', 'file');
        $config->set('auth.recovery.recovery_codes.storage_path', $codesPath);
        $config->set('auth.recovery.evidence.required_kinds', [RecoveryEvidenceKind::RecoveryCode]);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7601,
                'identifier' => 'rc-user@example.com',
                'password_hash' => password_hash('rc-oldpass-9', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $manager = $app->make(RecoveryManagerInterface::class);
        $identity = $app->make(IdentityProviderInterface::class)->findByIdentifier('rc-user@example.com');
        self::assertNotNull($identity);
        $codeStore = $app->make(RecoveryCodeStoreInterface::class);
        $plainCode = 'ABCD-1234-EFGH-5678';
        $codeStore->attachBatch(new IdentityReference($identity->identifier(), $identity->type()), [
            new RecoveryCode(
                codeHash: hash('sha256', $plainCode),
                identityIdentifier: (string) $identity->identifier(),
                identityType: $identity->type(),
                issuedAt: time(),
                code: $plainCode,
            ),
            new RecoveryCode(
                codeHash: hash('sha256', 'WXYZ-0000-WXYZ-0000'),
                identityIdentifier: (string) $identity->identifier(),
                identityType: $identity->type(),
                issuedAt: time(),
                code: 'WXYZ-0000-WXYZ-0000',
            ),
        ]);

        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'rc-user@example.com',
            transport: 'unit',
        ));
        self::assertNotEmpty($start->token);

        // Without evidence -> rejected (missing required kind).
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start->token,
                newPassword: 'Rc-pass-new-1!',
                transport: 'unit',
            ));
            self::fail('Expected RecoveryEvidenceRejectedException for missing recovery code.');
        } catch (RecoveryEvidenceRejectedException $e) {
            $missing = array_values(array_filter(
                $e->metadata['evidence_results'] ?? [],
                static fn (array $r): bool => ($r['reason_code'] ?? null) === 'recovery.evidence.missing_required',
            ));
            self::assertNotEmpty($missing);
        }

        // With WRONG recovery code -> still rejected.
        $start2 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'rc-user@example.com',
            transport: 'unit',
        ));
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start2->token,
                newPassword: 'Rc-pass-new-1!',
                transport: 'unit',
                attributes: [
                    'recovery_evidences' => [
                        new RecoveryEvidence(RecoveryEvidenceKind::RecoveryCode, 'WRONG-CODE-0000'),
                    ],
                ],
            ));
            self::fail('Expected invalid recovery code to be rejected.');
        } catch (RecoveryEvidenceRejectedException) {
            // Expected.
        }

        // With CORRECT recovery code -> success.
        $start3 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'rc-user@example.com',
            transport: 'unit',
        ));
        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $start3->token,
            newPassword: 'Rc-pass-new-1!',
            transport: 'unit',
            attributes: [
                'recovery_evidences' => [
                    new RecoveryEvidence(RecoveryEvidenceKind::RecoveryCode, $plainCode),
                ],
            ],
        ));
        self::assertTrue($result->completed);
        // De 2 códigos iniciales, 1 fue consumido atómicamente durante la verificación MFA
        // (ABCD-1234), por lo que rotateForIdentity invalida el restante (WXYZ) → total 1.
        self::assertSame(1, (int) ($result->metadata['recovery_codes_rotated'] ?? 0));

        // Reuse -> code already consumed, also other codes rotated -> should fail.
        $start4 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: 'rc-user@example.com',
            transport: 'unit',
        ));
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::PasswordReset,
                token: $start4->token,
                newPassword: 'Rc-pass-new-2!',
                transport: 'unit',
                attributes: [
                    'recovery_evidences' => [
                        new RecoveryEvidence(RecoveryEvidenceKind::RecoveryCode, $plainCode),
                    ],
                ],
            ));
            self::fail('Expected reused/rotated recovery code to be rejected.');
        } catch (RecoveryEvidenceRejectedException) {
            // Expected.
        }

        $events = $this->readJsonl($auditPath);
        $rejected = array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === RecoveryAuditEvent::ACTION_EVIDENCE_REJECTED,
        ));
        self::assertNotEmpty($rejected);
        $completed = array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === RecoveryAuditEvent::ACTION_COMPLETED
                && ($e['payload']['recovery_codes_rotated'] ?? 0) === 1,
        ));
        self::assertNotEmpty($completed);
    }

    public function test_federated_link_recovery_requires_evidence_and_completes_when_linked(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volt-ar-fl-' . uniqid('', true);
        mkdir($basePath, 0777, true);
        $auditPath = $basePath . DIRECTORY_SEPARATOR . 'audit.jsonl';
        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.recovery.audit.driver', 'file');
        $config->set('auth.recovery.audit.storage_path', $auditPath);
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7701,
                'identifier' => 'fl-user@example.com',
                'password_hash' => password_hash('fl-old-pw-11', PASSWORD_DEFAULT),
                'type' => 'user',
                'federated_links' => [
                    [
                        'rp_id' => 'accounts.google.com',
                        'sub' => 'google|user-fl-12345',
                    ],
                ],
            ],
        ]);
        $manager = $app->make(RecoveryManagerInterface::class);
        $identity = $app->make(IdentityProviderInterface::class)->findByIdentifier('fl-user@example.com');
        self::assertNotNull($identity);

        // FederatedLinkRecovery purpose without evidence -> implicit required kind FederatedLink.
        $start = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::FederatedLinkRecovery,
            identifier: 'fl-user@example.com',
            transport: 'unit',
        ));
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::FederatedLinkRecovery,
                token: $start->token,
                newPassword: 'Fl-new-pass-1!',
                transport: 'unit',
            ));
            self::fail('Expected implicit FederatedLink evidence requirement.');
        } catch (RecoveryEvidenceRejectedException $e) {
            $missing = array_values(array_filter(
                $e->metadata['evidence_results'] ?? [],
                static fn (array $r): bool => ($r['kind'] ?? null) === RecoveryEvidenceKind::FederatedLink
                    && ($r['reason_code'] ?? null) === 'recovery.evidence.missing_required',
            ));
            self::assertNotEmpty($missing);
        }

        // Wrong RP pair -> evidence rejected.
        $start2 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::FederatedLinkRecovery,
            identifier: 'fl-user@example.com',
            transport: 'unit',
        ));
        try {
            $manager->continueRecovery(new RecoveryContinuationRequest(
                purpose: RecoveryPurpose::FederatedLinkRecovery,
                token: $start2->token,
                newPassword: 'Fl-new-pass-1!',
                transport: 'unit',
                attributes: [
                    'recovery_evidences' => [
                        new RecoveryEvidence(
                            RecoveryEvidenceKind::FederatedLink,
                            json_encode(['rp_id' => 'accounts.google.com', 'sub' => 'google|WRONG'], JSON_THROW_ON_ERROR),
                        ),
                    ],
                ],
            ));
            self::fail('Expected wrong federated link to be rejected.');
        } catch (RecoveryEvidenceRejectedException) {
            // Expected.
        }

        // Correct pair (via assoc array + explicit sub) -> completes.
        $start3 = $manager->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::FederatedLinkRecovery,
            identifier: 'fl-user@example.com',
            transport: 'unit',
        ));
        $result = $manager->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::FederatedLinkRecovery,
            token: $start3->token,
            newPassword: 'Fl-new-pass-1!',
            transport: 'unit',
            attributes: [
                'recovery_evidences' => [
                    new RecoveryEvidence(
                        RecoveryEvidenceKind::FederatedLink,
                        'rp_id=accounts.google.com; sub=google|user-fl-12345',
                        [
                            'rp_id' => 'accounts.google.com',
                            'sub' => 'google|user-fl-12345',
                        ],
                    ),
                ],
            ],
        ));
        self::assertTrue($result->completed);
        self::assertSame(RecoveryPurpose::FederatedLinkRecovery, $result->purpose);

        $events = $this->readJsonl($auditPath);
        $requestedWithFederatedPurpose = array_values(array_filter(
            $events,
            static fn (array $e): bool => ($e['action'] ?? null) === 'recovery_requested'
                && ($e['purpose'] ?? null) === RecoveryPurpose::FederatedLinkRecovery->value,
        ));
        self::assertNotEmpty($requestedWithFederatedPurpose);
    }

    private function generateTotpCode(string $base32Secret, int $period = 30, string $algo = 'sha1', int $digits = 6): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(trim($base32Secret));
        if ($secret === '') {
            return str_pad('0', $digits, '0', STR_PAD_LEFT);
        }
        $binary = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($secret) as $c) {
            $v = strpos($chars, $c);
            if ($v === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $binary .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        $time = (int) floor(time() / $period);
        $msg = pack('J', $time);
        $hash = hash_hmac($algo, $msg, $binary !== '' ? $binary : random_bytes(20), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0xF;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** $digits);

        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            return [];
        }

        $records = [];

        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }
}
