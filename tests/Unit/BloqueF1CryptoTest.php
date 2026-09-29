<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\BearerAuthenticator;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Passkeys\AssertionResult;
use Quantum\Auth\Passkeys\CoseKey;
use Quantum\Auth\Passkeys\CoseSignatureVerifier;
use Quantum\Auth\Passkeys\FilePasskeyCredentialStore;
use Quantum\Auth\Passkeys\InMemoryPasskeyCredentialStore;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Passkeys\PasskeyAuthenticator;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Passkeys\PasskeyRegistrationCeremony;
use Quantum\Auth\Passkeys\RelyingPartyConfig;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\CompositeAuthenticatorResolver;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;

final class BloqueF1CryptoTest extends TestCase
{
    public function test_cose_key_es256_p256_parses_from_raw_cbor_bytes_and_returns_openssl_pem(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $x = str_repeat("\x01", 32);
            $y = str_repeat("\x02", 32);
        } else {
            $x = $kp['x'];
            $y = $kp['y'];
        }
        $coseKeyData = [
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => $x,
            -3 => $y,
        ];

        $cose = CoseKey::fromMap($coseKeyData);
        self::assertSame(-7, $cose->algorithm);
        self::assertSame(2, $cose->kty);
        self::assertSame(1, $cose->curve);
        self::assertSame(32, strlen($cose->x ?? ''));
        self::assertSame(32, strlen($cose->y ?? ''));

        $pem = $cose->toPemPublicKey();
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        self::assertStringEndsWith('-----END PUBLIC KEY-----' . "\n", $pem);
        self::assertGreaterThan(150, strlen($pem));

        if ($kp === null) {
            $this->markTestSkipped('OpenSSL private key generation unavailable in this PHP/Windows environment; COSE structural parsing verified.');
        }

        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertSame('EC', $details['type']);
    }

    public function test_cose_key_rs256_rsa_parses_from_cbor_and_returns_valid_rsa_pem(): void
    {
        $kp = self::tryLoadRsaKeypair();
        if ($kp === null) {
            $n = str_repeat("\xaa", 128);
            $e = "\x01\x00\x01";
        } else {
            $n = $kp['n'];
            $e = $kp['e'];
        }
        $coseKeyData = [
            1 => 3,
            3 => -257,
            -1 => $n,
            -2 => $e,
        ];

        $cose = CoseKey::fromMap($coseKeyData);
        self::assertSame(-257, $cose->algorithm);
        self::assertSame(3, $cose->kty);
        self::assertGreaterThan(64, strlen($cose->n ?? ''));

        $pem = $cose->toPemPublicKey();
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);

        if ($kp === null) {
            $this->markTestSkipped('OpenSSL RSA key generation unavailable; COSE RSA structural parsing verified.');
        }

        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertSame('RSA', $details['type']);
    }

    public function test_cose_signature_verifier_es256_verifies_real_openssl_signature(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL EC keypair generation not available in this environment.');
        }
        $privateKey = $kp['private'];
        $x = $kp['x'];
        $y = $kp['y'];

        $message = 'authenticator_data_hash_concat_client_data_hash_test_001';
        $derSig = '';
        $ok = openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($ok, 'openssl_sign ES256 should succeed with valid EC private key');
        $rawSig = $this->ecDerToRaw64($derSig);
        self::assertSame(64, strlen($rawSig));

        $cose = CoseKey::fromMap([
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => $x,
            -3 => $y,
        ]);
        $verifier = new CoseSignatureVerifier();
        $result = $verifier->verify($message, $rawSig, $cose);
        self::assertTrue($result, 'ES256 signature with correct EC key must verify');

        $badSig = str_repeat("\x00", 64);
        self::assertFalse($verifier->verify($message, $badSig, $cose), 'Bad ES256 signature must fail');
    }

    public function test_cose_signature_verifier_rs256_verifies_real_openssl_rsa_signature(): void
    {
        $kp = self::tryLoadRsaKeypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL RSA keypair generation not available in this environment.');
        }
        $privateKey = $kp['private'];
        $n = $kp['n'];
        $e = $kp['e'];

        $message = 'rs256_test_message_auth_data_concat_client_data_hash';
        $signature = '';
        $ok = openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($ok, 'openssl_sign RS256 should succeed');
        self::assertGreaterThan(64, strlen($signature));

        $cose = CoseKey::fromMap([
            1 => 3,
            3 => -257,
            -1 => $n,
            -2 => $e,
        ]);
        $verifier = new CoseSignatureVerifier();
        $result = $verifier->verify($message, $signature, $cose);
        self::assertTrue($result, 'RS256 signature with correct RSA key must verify');

        $badSig = str_repeat("\x00", strlen($signature));
        self::assertFalse($verifier->verify($message, $badSig, $cose), 'Bad RS256 signature must fail');
    }

    public function test_registration_finish_performs_attestation_object_validation_real_cose_key(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL EC keypair generation unavailable; skipping attestation signature test.');
        }
        $privateKey = $kp['private'];
        $x = $kp['x'];
        $y = $kp['y'];

        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'localhost', 'rp_name' => 'Test RP']);
        $ceremony = new PasskeyRegistrationCeremony($rp);
        $begin = $ceremony->beginRegistration('usr_001', 't@test.com', 'Test');
        $clientDataJSON = base64_encode(json_encode([
            'type' => 'webauthn.create',
            'challenge' => $begin['challenge'],
            'origin' => 'https://localhost',
        ], JSON_THROW_ON_ERROR));

        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = "\x41";
        $signCount = pack('N', 0);
        $aaguid = str_repeat("\x00", 16);
        $credId = str_repeat("\xab", 16);
        $credIdLen = pack('n', 16);

        $cborPublicKey = $this->encodeCoseKeyCbor(2, -7, 1, $x, $y);
        $authData = $rpIdHash . $flags . $signCount . $aaguid . $credIdLen . $credId . $cborPublicKey;

        $clientDataDecoded = base64_decode($clientDataJSON);
        self::assertNotFalse($clientDataDecoded);
        $clientDataHash = hash('sha256', $clientDataDecoded, true);
        $message = $authData . $clientDataHash;
        $derSig = '';
        openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = $this->ecDerToRaw64($derSig);

        $record = $ceremony->finishAttestation([
            'client_data_json_b64' => $clientDataJSON,
            'authenticator_data_b64' => base64_encode($authData),
            'signature_b64' => base64_encode($signature),
            'credential_id_b64' => base64_encode($credId),
            'user_handle' => 'usr_001',
            'transports' => ['internal'],
            'attestation_format' => 'packed',
        ]);

        self::assertInstanceOf(PasskeyCredentialRecord::class, $record);
        self::assertSame(bin2hex($credId), $record->credentialId);
        self::assertSame('usr_001', $record->userHandle);
        self::assertSame('localhost', $record->rpId);
        self::assertSame(0, $record->signCount);
        self::assertNotEmpty($record->credentialPublicKey);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $record->credentialPublicKey);
    }

    public function test_assertion_finish_validates_signature_and_updates_sign_count_real(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL EC keypair generation unavailable; skipping assertion signature test.');
        }
        $privateKey = $kp['private'];
        $x = $kp['x'];
        $y = $kp['y'];

        $credIdBin = str_repeat("\xcd", 16);
        $credIdHex = bin2hex($credIdBin);
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'localhost']);
        $store = new InMemoryPasskeyCredentialStore();
        $cosePem = CoseKey::fromMap([
            1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y,
        ])->toPemPublicKey();
        $store->save(new PasskeyCredentialRecord(
            credentialId: $credIdHex,
            credentialPublicKey: $cosePem,
            userHandle: 'usr_assert_001',
            rpId: 'localhost',
            signCount: 3,
            createdAt: time(),
            transports: ['internal'],
        ));

        $ceremony = new PasskeyAssertionCeremony($rp);
        $begin = $ceremony->beginAssertion('usr_assert_001');
        $clientDataJSON = base64_encode(json_encode([
            'type' => 'webauthn.get',
            'challenge' => $begin['challenge'],
            'origin' => 'https://localhost',
        ], JSON_THROW_ON_ERROR));

        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = "\x41";
        $newSignCount = 4;
        $signCountBin = pack('N', $newSignCount);
        $authData = $rpIdHash . $flags . $signCountBin;

        $clientDataDecoded = base64_decode($clientDataJSON);
        self::assertNotFalse($clientDataDecoded);
        $clientDataHash = hash('sha256', $clientDataDecoded, true);
        $message = $authData . $clientDataHash;
        $derSig = '';
        openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = $this->ecDerToRaw64($derSig);

        $result = $ceremony->verifyAssertion([
            'client_data_json_b64' => $clientDataJSON,
            'authenticator_data_b64' => base64_encode($authData),
            'signature_b64' => base64_encode($signature),
            'credential_id_b64' => base64_encode($credIdBin),
            'user_handle' => 'usr_assert_001',
        ], $store);

        self::assertTrue($result->isValid);
        self::assertSame($credIdHex, $result->credentialId);
        self::assertSame('usr_assert_001', $result->userHandle);
        self::assertSame($newSignCount, $result->signCountIncremented);
        self::assertFalse($result->metadata['simulated'] ?? true);
        self::assertSame('es256_p256_openssl', $result->metadata['signature_alg']);

        $updated = $store->findByCredentialId($credIdHex);
        self::assertNotNull($updated);
        self::assertSame($newSignCount, $updated->signCount);
    }

    public function test_assertion_sign_count_rollback_detection_returns_invalid(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL EC keypair generation unavailable; skipping sign count test.');
        }
        $privateKey = $kp['private'];
        $x = $kp['x'];
        $y = $kp['y'];

        $credIdBin = str_repeat("\xef", 16);
        $credIdHex = bin2hex($credIdBin);
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'localhost']);
        $store = new InMemoryPasskeyCredentialStore();
        $cosePem = CoseKey::fromMap([
            1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y,
        ])->toPemPublicKey();
        $store->save(new PasskeyCredentialRecord(
            credentialId: $credIdHex,
            credentialPublicKey: $cosePem,
            userHandle: 'usr_rollback',
            rpId: 'localhost',
            signCount: 10,
            createdAt: time(),
        ));

        $ceremony = new PasskeyAssertionCeremony($rp);
        $begin = $ceremony->beginAssertion('usr_rollback');
        $clientDataJSON = base64_encode(json_encode([
            'type' => 'webauthn.get',
            'challenge' => $begin['challenge'],
            'origin' => 'https://localhost',
        ], JSON_THROW_ON_ERROR));

        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = "\x41";
        $signCountBin = pack('N', 5);
        $authData = $rpIdHash . $flags . $signCountBin;

        $clientDataDecoded = base64_decode($clientDataJSON);
        self::assertNotFalse($clientDataDecoded);
        $clientDataHash = hash('sha256', $clientDataDecoded, true);
        $message = $authData . $clientDataHash;
        $derSig = '';
        openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = $this->ecDerToRaw64($derSig);

        $result = $ceremony->verifyAssertion([
            'client_data_json_b64' => $clientDataJSON,
            'authenticator_data_b64' => base64_encode($authData),
            'signature_b64' => base64_encode($signature),
            'credential_id_b64' => base64_encode($credIdBin),
            'user_handle' => 'usr_rollback',
        ], $store);

        self::assertFalse($result->isValid);
        self::assertSame('sign_count_rollback', $result->metadata['failure_reason']);
    }

    public function test_passkey_authenticator_is_authenticator_interface_and_resolves_via_composite_priority_900(): void
    {
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'localhost']);
        $store = new InMemoryPasskeyCredentialStore();
        $passkeyAuth = new PasskeyAuthenticator($rp, $store);
        self::assertInstanceOf(AuthenticatorInterface::class, $passkeyAuth);

        $idp = $this->createMock(IdentityProviderInterface::class);
        $policy = $this->createMock(PasswordPolicyInterface::class);
        $sessionAuth = new SessionAuthenticator(
            $this->createMock(\Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface::class),
        );
        $passwordAuth = new PasswordAuthenticator($idp, $policy);
        $bearerAuth = new BearerAuthenticator(
            $idp,
            $this->createMock(OpaqueTokenRepositoryInterface::class),
        );

        $passwordResolver = new DefaultAuthenticatorResolver($sessionAuth, $passwordAuth, $bearerAuth);
        $passkeyOnlyResolver = new class($passkeyAuth) implements \Quantum\Auth\Contracts\AuthenticatorResolverInterface {
            public function __construct(private readonly AuthenticatorInterface $auth) {}
            /** @return list<AuthenticatorInterface> */
            public function resolve(AuthenticationOperationContext $context): array { return [$this->auth]; }
        };

        $composite = new CompositeAuthenticatorResolver();
        $composite->addResolver($passwordResolver, 500);
        $composite->addResolver($passkeyOnlyResolver, 900);

        $resolvers = $composite->resolvers();
        self::assertCount(2, $resolvers);
        self::assertSame($passkeyOnlyResolver, $resolvers[0], 'passkey resolver priority 900 must come before password resolver priority 500');
        self::assertSame($passwordResolver, $resolvers[1]);

        $passkeyRequestAttrs = new AuthenticationRequest(
            requestId: 'req-passkey-001',
            attributes: [
                'passkey_client_data_json_b64' => 'Y2Q=',
                'passkey_authenticator_data_b64' => 'YWQ=',
                'passkey_signature_b64' => 'c2ln',
                'passkey_credential_id_b64' => 'Y2lk',
            ],
        );
        $passkeyCtx = new AuthenticationOperationContext(operation: 'authenticate', request: $passkeyRequestAttrs);
        self::assertTrue($passkeyAuth->supports($passkeyCtx), 'PasskeyAuthenticator must support request with passkey_* attributes');
    }

    public function test_file_passkey_store_persists_records_across_instances_with_sanitized_paths(): void
    {
        $tmpDir = sys_get_temp_dir() . '/volt_passkeys_' . bin2hex(random_bytes(8));
        @mkdir($tmpDir, 0755, true);

        try {
            $store1 = new FilePasskeyCredentialStore($tmpDir);
            $record = PasskeyCredentialRecord::fromArray([
                'credential_id' => 'file_cred_' . bin2hex(random_bytes(4)),
                'credential_public_key' => '-----BEGIN PUBLIC KEY----- mock -----END PUBLIC KEY-----',
                'user_handle' => 'usr_file_001',
                'rp_id' => 'localhost',
                'sign_count' => 7,
                'transports' => ['usb', 'internal'],
            ]);

            $store1->save($record);
            $found = $store1->findByCredentialId($record->credentialId);
            self::assertNotNull($found);
            self::assertSame(7, $found->signCount);

            $store2 = new FilePasskeyCredentialStore($tmpDir);
            $cross = $store2->findByCredentialId($record->credentialId);
            self::assertNotNull($cross);
            self::assertSame($record->credentialId, $cross->credentialId);
            self::assertSame($record->userHandle, $cross->userHandle);

            $list = $store2->listForUserHandle('usr_file_001');
            self::assertCount(1, $list);

            $badId = '../escape_attempt_should_not_create_file';
            $this->expectException(\InvalidArgumentException::class);
            $store2->findByCredentialId($badId);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_assertion_rejects_rp_id_mismatch_and_wrong_rp_id_hash(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            $this->markTestSkipped('OpenSSL EC keypair generation unavailable; skipping RP ID hash test.');
        }
        $privateKey = $kp['private'];
        $x = $kp['x'];
        $y = $kp['y'];

        $credIdBin = str_repeat("\x99", 16);
        $credIdHex = bin2hex($credIdBin);
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'correct.example.com']);
        $store = new InMemoryPasskeyCredentialStore();
        $cosePem = CoseKey::fromMap([
            1 => 2, 3 => -7, -1 => 1, -2 => $x, -3 => $y,
        ])->toPemPublicKey();
        $store->save(new PasskeyCredentialRecord(
            credentialId: $credIdHex,
            credentialPublicKey: $cosePem,
            userHandle: 'usr_rp_mismatch',
            rpId: 'correct.example.com',
            signCount: 2,
            createdAt: time(),
        ));

        $ceremony = new PasskeyAssertionCeremony($rp);
        $begin = $ceremony->beginAssertion('usr_rp_mismatch');
        $clientDataJSON = base64_encode(json_encode([
            'type' => 'webauthn.get',
            'challenge' => $begin['challenge'],
            'origin' => 'https://evil.example.com',
        ], JSON_THROW_ON_ERROR));

        $wrongRpIdHash = hash('sha256', 'evil.example.com', true);
        $flags = "\x41";
        $signCountBin = pack('N', 3);
        $authData = $wrongRpIdHash . $flags . $signCountBin;

        $clientDataDecoded = base64_decode($clientDataJSON);
        self::assertNotFalse($clientDataDecoded);
        $clientDataHash = hash('sha256', $clientDataDecoded, true);
        $message = $authData . $clientDataHash;
        $derSig = '';
        openssl_sign($message, $derSig, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = $this->ecDerToRaw64($derSig);

        $result = $ceremony->verifyAssertion([
            'client_data_json_b64' => $clientDataJSON,
            'authenticator_data_b64' => base64_encode($authData),
            'signature_b64' => base64_encode($signature),
            'credential_id_b64' => base64_encode($credIdBin),
            'user_handle' => 'usr_rp_mismatch',
        ], $store);

        self::assertFalse($result->isValid);
        self::assertSame('rp_id_hash_mismatch', $result->metadata['failure_reason']);
    }

    /**
     * @return array{private: mixed, x: string, y: string}|null
     */
    private static function tryLoadEcP256Keypair(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $config = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $private = @openssl_pkey_new($config);
        if ($private === false) {
            $cached = null;
            return null;
        }
        $details = @openssl_pkey_get_details($private);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            $cached = null;
            return null;
        }
        $result = ['private' => $private, 'x' => $details['ec']['x'], 'y' => $details['ec']['y']];
        $cached = $result;
        return $result;
    }

    /**
     * @return array{private: mixed, n: string, e: string}|null
     */
    private static function tryLoadRsaKeypair(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $config = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $private = @openssl_pkey_new($config);
        if ($private === false) {
            $cached = null;
            return null;
        }
        $details = @openssl_pkey_get_details($private);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            $cached = null;
            return null;
        }
        $result = ['private' => $private, 'n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
        $cached = $result;
        return $result;
    }

    private function ecDerToRaw64(string $der): string
    {
        if (strlen($der) < 8) {
            return str_repeat("\x00", 64);
        }
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            return str_repeat("\x00", 64);
        }
        $offset++;
        $len = ord($der[$offset]);
        $offset++;
        if ($len & 0x80) {
            $n = $len & 0x7f;
            $offset += $n;
        }

        $parseInt = function () use (&$der, &$offset): string {
            if ($offset >= strlen($der) || ord($der[$offset]) !== 0x02) {
                return '';
            }
            $offset++;
            $l = ord($der[$offset]);
            $offset++;
            if ($l & 0x80) {
                $n = $l & 0x7f;
                $lenBytes = substr($der, $offset, $n);
                $offset += $n;
                $l = 0;
                foreach (str_split($lenBytes) as $b) {
                    $l = ($l << 8) | ord($b);
                }
            }
            $intBytes = substr($der, $offset, $l);
            $offset += $l;
            while (strlen($intBytes) > 0 && ord($intBytes[0]) === 0x00) {
                $intBytes = substr($intBytes, 1);
            }
            return $intBytes;
        };

        $r = $parseInt();
        $s = $parseInt();

        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        if (strlen($r) > 32) { $r = substr($r, -32); }
        if (strlen($s) > 32) { $s = substr($s, -32); }
        return $r . $s;
    }

    private function encodeCoseKeyCbor(int $kty, int $alg, int $curve, string $x, string $y): string
    {
        $result = "\xa5";
        $result .= $this->cborInt(1) . $this->cborInt($kty);
        $result .= $this->cborInt(3) . $this->cborInt($alg);
        $result .= $this->cborInt(-1) . $this->cborInt($curve);
        $result .= $this->cborInt(-2) . $this->cborBytes($x);
        $result .= $this->cborInt(-3) . $this->cborBytes($y);
        return $result;
    }

    private function cborInt(int $v): string
    {
        if ($v >= 0) {
            if ($v < 24) return chr($v);
            if ($v < 256) return "\x18" . chr($v);
        } else {
            $nv = -1 - $v;
            if ($nv < 24) return chr(0x20 + $nv);
            if ($nv < 256) return "\x38" . chr($nv);
        }
        throw new \RuntimeException('cbor int out of range for test encoder');
    }

    private function cborBytes(string $data): string
    {
        $len = strlen($data);
        if ($len < 24) return chr(0x40 + $len);
        if ($len < 256) return "\x58" . chr($len) . $data;
        return "\x59" . pack('n', $len) . $data;
    }

    private function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) return;
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
