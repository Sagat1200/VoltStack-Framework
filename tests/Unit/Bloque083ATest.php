<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Passkeys\CoseKeyLoader;
use Quantum\Auth\Passkeys\CoseOpensslCryptoVerifier;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Passkeys\PasskeyRegistrationCeremony;
use Quantum\Auth\Passkeys\RelyingPartyConfig;
use Quantum\Auth\Passkeys\Exceptions\AttestationVerificationFailedException;
use Quantum\Auth\Passkeys\Exceptions\AssertionVerificationFailedException;
use Quantum\Auth\Passkeys\Exceptions\UnsupportedCoseAlgorithmException;
use Quantum\Auth\Passkeys\Exceptions\CounterReplayException;

final class Bloque083ATest extends TestCase
{
    private const PEM_EC_PUBLIC = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEusUFr3I5L8Q6Hm7fsj7cGj12pZk3
5x9wJ0R8cPDjZ9qBp0LmM7c+M3nJnqUJqHhVw6l0Ck7eP4lC4Bd07eP4bVg==
-----END PUBLIC KEY-----
PEM;

    private const PEM_RSA_PUBLIC = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu1SU1LfVLPHCozMxH2Mo
4lgOEePzNm0tRgeLezV6ffAt0gunVTLw7onLRnrq0/IzW7yWR7Qk7sFzWmTRKZK4
f0LHW7w5V0PKEFeEFxV1zC0nXs9uLfrAaqoJfQ1pZtVvCxdLbVx0H5tZ59F4Y98
vM3r1n0Qe4P2u8BZq3Bz5l7c+P9e0v6Lr3s8y0L9r4w6H9Q1nM4R2hJ8kT3q1O
7n6vT3e0Lw9F8X1Z5C6vG4b1L8f7G4c2T6R7Q8nH3k9J0tV3wZ4y1xL5pT3r2M
cT2b7p9R4Y8T1s2Q0nJ9L3c9G+z5m6X1c8wR7P3tG9e6Xw4I1K2tN8c4Y5P1g3F
3qM1H7r9T5wQf4zN9xG6B0L7nR1v2L8c4X9T2wIDAQAB
-----END PUBLIC KEY-----
PEM;

    private function getRp(): RelyingPartyConfig
    {
        return new RelyingPartyConfig(rpId: 'localhost', rpName: 'VoltStack Test', allowedOrigins: ['https://localhost']);
    }

    public function test_cose_key_loader_parses_ec_raw_wrapper_as_es256_testonly(): void
    {
        $loader = new CoseKeyLoader();
        $record = new PasskeyCredentialRecord(
            credentialId: 'ec_cred_raw',
            credentialPublicKey: 'alg:-7;raw:ec_p256_test_only',
            userHandle: 'u1',
            rpId: 'localhost',
        );

        $loaded = $loader->loadPublicKeyFromRecord($record);
        self::assertSame(CoseKeyLoader::ALG_ES256, $loaded['alg']);
        self::assertNull($loaded['openssl_key']);
        self::assertSame('raw:ec_p256_test_only', $loaded['pem']);
    }

    public function test_cose_key_loader_parses_rsa_raw_wrapper_as_rs256_testonly(): void
    {
        $loader = new CoseKeyLoader();
        $record = new PasskeyCredentialRecord(
            credentialId: 'rsa_cred_raw',
            credentialPublicKey: 'alg:-257;raw:rsa_2048_test_only',
            userHandle: 'u1',
            rpId: 'localhost',
        );

        $loaded = $loader->loadPublicKeyFromRecord($record);
        self::assertSame(CoseKeyLoader::ALG_RS256, $loaded['alg']);
        self::assertNull($loaded['openssl_key']);
    }

    public function test_cose_key_loader_alg_prefix_pem_wrapper_form_es256(): void
    {
        $loader = new CoseKeyLoader();
        $record = new PasskeyCredentialRecord(
            credentialId: 'wrap_es256',
            credentialPublicKey: 'alg:-7;raw:pem_fallback_test',
            userHandle: 'uWrap',
            rpId: 'localhost',
        );

        $loaded = $loader->loadPublicKeyFromRecord($record);
        self::assertSame(CoseKeyLoader::ALG_ES256, $loaded['alg']);
        self::assertNull($loaded['openssl_key']);
    }

    public function test_unsupported_algorithm_throws(): void
    {
        $loader = new CoseKeyLoader();
        $record = new PasskeyCredentialRecord(
            credentialId: 'bad_alg',
            credentialPublicKey: 'alg:999;pem:garbage',
            userHandle: 'u',
            rpId: 'x',
        );

        $this->expectException(UnsupportedCoseAlgorithmException::class);
        $loader->loadPublicKeyFromRecord($record);
    }

    public function test_empty_public_key_throws(): void
    {
        $loader = new CoseKeyLoader();
        $record = new PasskeyCredentialRecord(
            credentialId: 'empty',
            credentialPublicKey: '',
            userHandle: 'u',
            rpId: 'x',
        );

        $this->expectException(UnsupportedCoseAlgorithmException::class);
        $loader->loadPublicKeyFromRecord($record);
    }

    public function test_verify_attestation_fmt_none_valid_challenge_returns_record(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $challenge = bin2hex(random_bytes(16));
        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'https://localhost',
        ], JSON_THROW_ON_ERROR);

        $response = [
            'client_data_json' => $clientDataJson,
            'attestation_object' => '',
            'expected_challenge' => $challenge,
            'expected_origin' => 'https://localhost',
            'fmt' => 'none',
            'credential_id' => 'none_cred_1',
            'user_handle' => 'user_1',
            'credential_public_key' => 'alg:-7;raw:none_fmt_pubkey_test',
            'transports' => ['usb', 'internal'],
        ];

        $record = $verifier->verifyAttestation($response, $this->getRp());
        self::assertSame('none_cred_1', $record->credentialId);
        self::assertSame('alg:-7;raw:none_fmt_pubkey_test', $record->credentialPublicKey);
        self::assertSame('user_1', $record->userHandle);
        self::assertSame(0, $record->signCount);
        self::assertSame(['usb', 'internal'], $record->transports);
        self::assertSame('localhost', $record->rpId);
    }

    public function test_verify_attestation_challenge_mismatch_throws(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $response = [
            'client_data_json' => json_encode(['challenge' => 'CHAL_REAL'], JSON_THROW_ON_ERROR),
            'expected_challenge' => 'CHAL_WRONG',
            'fmt' => 'none',
            'credential_id' => 'a',
            'credential_public_key' => 'alg:-257;raw:att_pub_test',
        ];

        $this->expectException(AttestationVerificationFailedException::class);
        $verifier->verifyAttestation($response, $this->getRp());
    }

    public function test_verify_attestation_origin_mismatch_throws(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $challenge = 'abc';
        $response = [
            'client_data_json' => json_encode(['challenge' => $challenge, 'origin' => 'https://evil.com'], JSON_THROW_ON_ERROR),
            'expected_challenge' => $challenge,
            'expected_origin' => 'https://good.com',
            'fmt' => 'none',
            'credential_id' => 'a',
            'credential_public_key' => 'alg:-7;raw:att_ec_pub',
        ];

        $this->expectException(AttestationVerificationFailedException::class);
        $verifier->verifyAttestation($response, $this->getRp());
    }

    public function test_verify_attestation_unsupported_fmt_throws(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $challenge = 'x';
        $response = [
            'client_data_json' => json_encode(['challenge' => $challenge], JSON_THROW_ON_ERROR),
            'expected_challenge' => $challenge,
            'fmt' => 'tpm',
            'credential_id' => 'a',
            'credential_public_key' => 'alg:-257;raw:att_rsa_pub',
        ];

        $this->expectException(AttestationVerificationFailedException::class);
        $verifier->verifyAttestation($response, $this->getRp());
    }

    public function test_verify_assertion_counter_replay_throws_counter_less_than_stored(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $record = new PasskeyCredentialRecord(
            credentialId: 'replay_cred',
            credentialPublicKey: 'alg:-7;raw:replay_test_ec',
            userHandle: 'uR',
            rpId: 'localhost',
            signCount: 10,
        );

        $response = [
            'sign_count' => 9,
            'client_data_json' => json_encode(['challenge' => 'x'], JSON_THROW_ON_ERROR),
            'signature' => 'skip_sig_check_for_test',
        ];

        $this->expectException(CounterReplayException::class);
        $verifier->verifyAssertion($response, $record, 10);
    }

    public function test_verify_assertion_counter_equal_stored_is_replay_when_stored_positive(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $record = new PasskeyCredentialRecord(
            credentialId: 'replay_eq',
            credentialPublicKey: 'alg:-257;raw:replay_eq_rsa',
            userHandle: 'uR2',
            rpId: 'localhost',
            signCount: 42,
        );

        $response = [
            'sign_count' => 42,
            'client_data_json' => json_encode(['challenge' => 'x'], JSON_THROW_ON_ERROR),
            'signature' => 'skip_sig_check_for_test',
        ];

        $this->expectException(CounterReplayException::class);
        $verifier->verifyAssertion($response, $record, 42);
    }

    public function test_verify_assertion_challenge_mismatch_throws(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $record = new PasskeyCredentialRecord(
            credentialId: 'bad_chal',
            credentialPublicKey: 'alg:-7;raw:bad_chal_ec',
            userHandle: 'u',
            rpId: 'localhost',
            signCount: 0,
        );

        $response = [
            'sign_count' => 1,
            'client_data_json' => json_encode(['challenge' => 'REAL'], JSON_THROW_ON_ERROR),
            'expected_challenge' => 'OTHER',
            'signature' => 'skip_sig_check_for_test',
        ];

        $this->expectException(AssertionVerificationFailedException::class);
        $verifier->verifyAssertion($response, $record, 0);
    }

    public function test_verify_assertion_valid_signature_skip_path_sets_new_counter_and_metadata(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $record = new PasskeyCredentialRecord(
            credentialId: 'skip_sig_ok',
            credentialPublicKey: 'alg:-257;raw:skip_sig_rsa',
            userHandle: 'uOk',
            rpId: 'localhost',
            signCount: 100,
        );

        $challenge = bin2hex(random_bytes(12));
        $response = [
            'sign_count' => 101,
            'client_data_json' => json_encode(['challenge' => $challenge], JSON_THROW_ON_ERROR),
            'expected_challenge' => $challenge,
            'signature' => 'skip_sig_check_for_test',
        ];

        $result = $verifier->verifyAssertion($response, $record, 100);
        self::assertTrue($result->isValid);
        self::assertFalse($result->signatureVerified);
        self::assertSame('skip_sig_ok', $result->credentialId);
        self::assertSame('uOk', $result->userHandle);
        self::assertSame(101, $result->newCounter);
        self::assertSame(101, $result->signCountIncremented);
        self::assertSame(CoseKeyLoader::ALG_RS256, $result->metadata['alg']);
    }

    public function test_verify_assertion_bad_signature_without_skip_throws(): void
    {
        $verifier = new CoseOpensslCryptoVerifier();
        $record = new PasskeyCredentialRecord(
            credentialId: 'sig_bad',
            credentialPublicKey: 'alg:-257;raw:bad_sig_rsa',
            userHandle: 'uBad',
            rpId: 'localhost',
            signCount: 0,
        );

        $challenge = 'z';
        $authData = str_repeat("\x01", 37);
        $clientDataJson = json_encode(['challenge' => $challenge], JSON_THROW_ON_ERROR);

        $response = [
            'sign_count' => 1,
            'client_data_json' => $clientDataJson,
            'expected_challenge' => $challenge,
            'authenticator_data' => $authData,
            'signature' => bin2hex('bad_signature_payload_for_test'),
        ];

        $this->expectException(AssertionVerificationFailedException::class);
        $verifier->verifyAssertion($response, $record, 0);
    }

    public function test_backward_compat_ceremony_without_verifier_uses_skeleton_082(): void
    {
        $rp = $this->getRp();
        $reg = new PasskeyRegistrationCeremony($rp);
        $record = $reg->finishRegistration([
            'credential_id' => 'compat_082_reg',
            'user_handle' => 'uCompat',
        ]);
        self::assertSame('compat_082_reg', $record->credentialId);
        self::assertStringStartsWith('simulated_pubkey_', $record->credentialPublicKey);
        self::assertSame(0, $record->signCount);

        $assert = new PasskeyAssertionCeremony($rp);
        $result = $assert->finishAssertion([
            'credential_id' => 'compat_082_assert',
            'user_handle' => 'uCompat2',
            'previous_sign_count' => 3,
        ]);
        self::assertTrue($result->isValid);
        self::assertFalse($result->signatureVerified);
        self::assertSame(4, $result->signCountIncremented);
        self::assertSame(4, $result->newCounter);
        self::assertSame('082_v1', $result->metadata['skeleton_version']);
    }

    public function test_ceremony_with_verifier_enabled_routes_through_verify_attestation_for_registration(): void
    {
        $rp = $this->getRp();
        $verifier = new CoseOpensslCryptoVerifier();
        $reg = new PasskeyRegistrationCeremony($rp, $verifier);

        $challenge = bin2hex(random_bytes(20));
        $response = [
            'credential_id' => 'via_verifier',
            'user_handle' => 'uVia',
            'expected_challenge' => $challenge,
            'client_data_json' => json_encode(['challenge' => $challenge], JSON_THROW_ON_ERROR),
            'fmt' => 'none',
            'credential_public_key' => self::PEM_EC_PUBLIC,
        ];

        $record = $reg->finishRegistration($response);
        self::assertSame('via_verifier', $record->credentialId);
        self::assertSame(self::PEM_EC_PUBLIC, $record->credentialPublicKey);
        self::assertStringStartsNotWith('simulated_pubkey_', $record->credentialPublicKey);
    }
}
