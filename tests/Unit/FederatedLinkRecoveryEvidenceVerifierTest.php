<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\FederatedIdentityLinkProviderInterface;
use Quantum\Auth\Contracts\FederatedLinkNonceStoreInterface;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Recovery\FederatedLinkRecoveryEvidenceVerifier;
use Quantum\Auth\Recovery\InMemoryFederatedLinkNonceStore;
use Quantum\Auth\Recovery\PasswordResetTokenRecord;
use Quantum\Auth\Recovery\RecoveryEvidence;
use Quantum\Auth\Recovery\RecoveryEvidenceKind;
use Quantum\Auth\Recovery\RecoveryEvidenceVerificationResult;
use Quantum\Auth\Recovery\RecoveryPurpose;

/**
 * Tests del verifier de FederatedLink con nonce binding (Bloque 8).
 */
class FederatedLinkRecoveryEvidenceVerifierTest extends TestCase
{
    private function identity(): IdentityReference
    {
        return new IdentityReference(new IdentityIdentifier('fl-user@example.com'), 'user');
    }

    private function tokenWithNonce(?string $nonce): PasswordResetTokenRecord
    {
        $attributes = $nonce !== null ? ['nonce' => $nonce] : [];

        return new PasswordResetTokenRecord(
            id: 'token-id-' . bin2hex(random_bytes(4)),
            secretHash: hash('sha256', 'secret'),
            reference: $this->identity(),
            identifier: 'fl-user@example.com',
            purpose: RecoveryPurpose::FederatedLinkRecovery,
            issuedAt: time(),
            expiresAt: time() + 3600,
            attributes: $attributes,
        );
    }

    private function linkProvider(bool $linked): FederatedIdentityLinkProviderInterface
    {
        return new class($linked) implements FederatedIdentityLinkProviderInterface {
            public function __construct(private readonly bool $linked) {}

            public function findByFederatedPair(string $rpId, string $subject): ?IdentityReference
            {
                return $this->linked ? new IdentityReference(new IdentityIdentifier('fl-user@example.com'), 'user') : null;
            }

            public function isLinkedTo(IdentityReference $identity, string $rpId, string $subject): bool
            {
                return $this->linked;
            }
        };
    }

    public function test_nonce_match_and_link_correct_passes(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce('nonce-abc-123');

        $result = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                    'nonce' => 'nonce-abc-123',
                ],
            ),
            $this->identity(),
            $token,
        );

        self::assertTrue($result->passed);
        self::assertSame('recovery.evidence.federated_link.linked', $result->reasonCode);
        self::assertTrue($result->metadata['nonce_bound'] ?? false);
    }

    public function test_nonce_mismatch_fails(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce('nonce-abc-123');

        $result = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                    'nonce' => 'nonce-DIFFERENT',
                ],
            ),
            $this->identity(),
            $token,
        );

        self::assertFalse($result->passed);
        self::assertSame('recovery.evidence.federated_link.nonce_mismatch', $result->reasonCode);
    }

    public function test_missing_nonce_in_evidence_fails_when_token_has_nonce(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce('nonce-abc-123');

        $result = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                ],
            ),
            $this->identity(),
            $token,
        );

        self::assertFalse($result->passed);
        self::assertSame('recovery.evidence.federated_link.nonce_mismatch', $result->reasonCode);
    }

    public function test_same_nonce_replayed_is_rejected(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce('nonce-replay-1');

        // Primer uso del nonce: éxito.
        $first = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                    'nonce' => 'nonce-replay-1',
                ],
            ),
            $this->identity(),
            $token,
        );
        self::assertTrue($first->passed, 'El primer uso del nonce debe pasar');

        // Segundo uso (replay): debe fallar.
        $second = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                    'nonce' => 'nonce-replay-1',
                ],
            ),
            $this->identity(),
            $token,
        );
        self::assertFalse($second->passed);
        self::assertSame('recovery.evidence.federated_link.nonce_replayed', $second->reasonCode);
    }

    public function test_legacy_token_without_nonce_skips_nonce_check(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce(null);

        $result = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|user-123',
                ],
            ),
            $this->identity(),
            $token,
        );

        self::assertTrue($result->passed, 'Token legacy sin nonce debe saltar la verificación de nonce');
        self::assertFalse($result->metadata['nonce_bound'] ?? true);
    }

    public function test_nonce_extracted_from_jwt_payload_matches(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), $nonceStore);
        $token = $this->tokenWithNonce('jwt-nonce-999');

        $payload = base64_encode(json_encode([
            'iss' => 'accounts.google.com',
            'sub' => 'google|user-123',
            'nonce' => 'jwt-nonce-999',
        ], JSON_THROW_ON_ERROR));
        $jwt = 'header.' . $payload . '.signature';

        $result = $verifier->verify(
            new RecoveryEvidence(RecoveryEvidenceKind::FederatedLink, $jwt),
            $this->identity(),
            $token,
        );

        self::assertTrue($result->passed);
        self::assertSame('accounts.google.com', $result->metadata['rp_id'] ?? null);
    }

    public function test_without_nonce_store_nonce_still_validated_but_not_consumed(): void
    {
        // Sin nonce store: se valida el match pero no hay replay protection persistente.
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(true), null);
        $token = $this->tokenWithNonce('nonce-no-store-1');

        $evidence = new RecoveryEvidence(
            RecoveryEvidenceKind::FederatedLink,
            '',
            [
                'rp_id' => 'accounts.google.com',
                'sub' => 'google|user-123',
                'nonce' => 'nonce-no-store-1',
            ],
        );

        $first = $verifier->verify($evidence, $this->identity(), $token);
        self::assertTrue($first->passed);

        // Sin store, no hay replay protection: el segundo uso también pasa (no se consume).
        $second = $verifier->verify($evidence, $this->identity(), $token);
        self::assertTrue($second->passed, 'Sin nonce store no hay replay protection');
    }

    public function test_wrong_link_fails_even_with_correct_nonce(): void
    {
        $nonceStore = new InMemoryFederatedLinkNonceStore();
        $verifier = new FederatedLinkRecoveryEvidenceVerifier($this->linkProvider(false), $nonceStore);
        $token = $this->tokenWithNonce('nonce-wrong-link');

        $result = $verifier->verify(
            new RecoveryEvidence(
                RecoveryEvidenceKind::FederatedLink,
                '',
                [
                    'rp_id' => 'accounts.google.com',
                    'sub' => 'google|WRONG',
                    'nonce' => 'nonce-wrong-link',
                ],
            ),
            $this->identity(),
            $token,
        );

        self::assertFalse($result->passed);
        self::assertSame('recovery.evidence.federated_link.not_linked', $result->reasonCode);
    }
}
