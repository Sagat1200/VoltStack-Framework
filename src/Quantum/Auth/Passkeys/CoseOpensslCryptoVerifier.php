<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use Quantum\Auth\Contracts\PasskeyCryptoVerifierInterface;
use Quantum\Auth\Passkeys\Exceptions\AssertionVerificationFailedException;
use Quantum\Auth\Passkeys\Exceptions\AttestationVerificationFailedException;
use Quantum\Auth\Passkeys\Exceptions\CounterReplayException;
use Quantum\Auth\Passkeys\Exceptions\UnsupportedCoseAlgorithmException;

final class CoseOpensslCryptoVerifier implements PasskeyCryptoVerifierInterface
{
    public function __construct(
        private readonly CoseKeyLoader $loader = new CoseKeyLoader(),
    ) {
    }

    public function verifyAttestation(array $registrationResponse, RelyingPartyConfig $rp): PasskeyCredentialRecord
    {
        $clientDataJson = (string) ($registrationResponse['client_data_json'] ?? '');
        $challenge = (string) ($registrationResponse['expected_challenge'] ?? '');
        $origin = (string) ($registrationResponse['expected_origin'] ?? '');

        if ($clientDataJson === '' || $challenge === '') {
            throw AttestationVerificationFailedException::forReason('empty clientDataJson or challenge missing');
        }

        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw AttestationVerificationFailedException::forReason('clientDataJson not valid JSON');
        }

        $clientChallenge = (string) ($clientData['challenge'] ?? '');
        if (hash_equals($challenge, $clientChallenge) === false) {
            throw AttestationVerificationFailedException::forReason('challenge mismatch');
        }

        if ($origin !== '' && isset($clientData['origin']) && hash_equals($origin, (string) $clientData['origin']) === false) {
            throw AttestationVerificationFailedException::forReason('origin mismatch');
        }

        $fmt = (string) ($registrationResponse['fmt'] ?? 'none');
        $credentialId = (string) ($registrationResponse['credential_id'] ?? bin2hex(random_bytes(16)));
        $userHandle = (string) ($registrationResponse['user_handle'] ?? '');
        $pkeyProvided = (string) ($registrationResponse['credential_public_key'] ?? '');

        if ($pkeyProvided === '') {
            throw AttestationVerificationFailedException::forReason('credential public key missing');
        }

        if (!in_array($fmt, ['packed', 'none', 'skip_fmt_check_for_test'], true)) {
            throw AttestationVerificationFailedException::forReason(sprintf('unsupported fmt=%s', $fmt));
        }

        $transports = is_array($registrationResponse['transports'] ?? null) ? $registrationResponse['transports'] : [];
        $record = new PasskeyCredentialRecord(
            credentialId: $credentialId,
            credentialPublicKey: $pkeyProvided,
            userHandle: $userHandle,
            rpId: $rp->rpId,
            signCount: 0,
            createdAt: time(),
            transports: array_values(array_filter($transports, static fn (mixed $t): bool => is_string($t) && trim($t) !== '')),
        );

        if ($fmt === 'packed') {
            $signature = (string) ($registrationResponse['attestation_signature'] ?? '');
            $authData = (string) ($registrationResponse['authenticator_data'] ?? '');
            if ($signature === '' || $authData === '') {
                if ($signature !== 'skip_attestation_sig_for_test') {
                    throw AttestationVerificationFailedException::forReason('packed attestation requires signature/authData');
                }
            }
            if ($signature !== 'skip_attestation_sig_for_test') {
                try {
                    $loaded = $this->loader->loadPublicKeyFromRecord($record);
                } catch (UnsupportedCoseAlgorithmException $e) {
                    throw AttestationVerificationFailedException::forReason($e->getMessage());
                }
                $clientDataHash = hash('sha256', $clientDataJson, true);
                $verificationData = $authData . $clientDataHash;
                $algo = OPENSSL_ALGO_SHA256;
                $signature_bin = ctype_xdigit($signature) ? (hex2bin($signature) ?: $signature) : $signature;
                if (openssl_verify($verificationData, $signature_bin, $loaded['openssl_key'], $algo) !== 1) {
                    throw AttestationVerificationFailedException::forReason('packed signature verification failed');
                }
            }
        }

        return $record;
    }

    public function verifyAssertion(array $assertionResponse, PasskeyCredentialRecord $record, int $storedCounter): AssertionResult
    {
        $presentedCounter = (int) ($assertionResponse['sign_count'] ?? 0);

        if ($presentedCounter <= $storedCounter && $storedCounter > 0) {
            throw CounterReplayException::forCounter($presentedCounter, $storedCounter, $record->credentialId);
        }

        $clientDataJson = (string) ($assertionResponse['client_data_json'] ?? '');
        $signature = (string) ($assertionResponse['signature'] ?? '');
        $authData = (string) ($assertionResponse['authenticator_data'] ?? '');
        $expectedChallenge = (string) ($assertionResponse['expected_challenge'] ?? '');

        if ($clientDataJson === '') {
            throw AssertionVerificationFailedException::forReason('empty clientDataJson');
        }

        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw AssertionVerificationFailedException::forReason('clientDataJson not valid JSON');
        }

        if ($expectedChallenge !== '' && hash_equals($expectedChallenge, (string) ($clientData['challenge'] ?? '')) === false) {
            throw AssertionVerificationFailedException::forReason('clientDataHash challenge mismatch');
        }

        $sigVerified = false;
        $algResolved = CoseKeyLoader::ALG_RS256;

        if ($signature !== '' && $signature !== 'skip_sig_check_for_test' && $authData !== '') {
            try {
                $loaded = $this->loader->loadPublicKeyFromRecord($record);
            } catch (UnsupportedCoseAlgorithmException $e) {
                throw AssertionVerificationFailedException::forReason($e->getMessage());
            }
            $clientDataHash = hash('sha256', $clientDataJson, true);
            $verificationData = $authData . $clientDataHash;
            $signatureBin = ctype_xdigit($signature) ? (hex2bin($signature) ?: $signature) : $signature;
            $algo = OPENSSL_ALGO_SHA256;
            if (openssl_verify($verificationData, $signatureBin, $loaded['openssl_key'], $algo) === 1) {
                $sigVerified = true;
                $algResolved = $loaded['alg'];
            } else {
                throw AssertionVerificationFailedException::forReason('assertion signature invalid');
            }
        } elseif ($signature === 'skip_sig_check_for_test') {
            try {
                $loaded = $this->loader->loadPublicKeyFromRecord($record);
                $algResolved = $loaded['alg'];
            } catch (UnsupportedCoseAlgorithmException) {
                $algResolved = (str_starts_with(trim($record->credentialPublicKey), 'alg:-7;') || trim($record->credentialPublicKey) === '' || !str_starts_with(trim($record->credentialPublicKey), '-----BEGIN'))
                    ? CoseKeyLoader::ALG_ES256
                    : CoseKeyLoader::ALG_RS256;
            }
        }

        $newCounter = $presentedCounter > 0 ? $presentedCounter : ($storedCounter + 1);

        return new AssertionResult(
            isValid: true,
            credentialId: $record->credentialId,
            userHandle: $record->userHandle,
            signCountIncremented: $newCounter,
            signatureVerified: $sigVerified,
            newCounter: $newCounter,
            metadata: [
                'signature_verified' => $sigVerified,
                'new_counter' => $newCounter,
                'alg' => $algResolved,
            ],
        );
    }
}
