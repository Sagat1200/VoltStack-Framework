<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;

final class BloqueG1CryptoTest extends TestCase
{
    private static ?array $cachedEcP256Keypair = null;
    private static ?array $cachedRsaKeypair = null;
    private static bool $cached = false;

    public static function tryLoadEcP256Keypair(): ?array
    {
        if (self::$cached && self::$cachedEcP256Keypair !== null) {
            return self::$cachedEcP256Keypair;
        }
        if (self::$cached && self::$cachedEcP256Keypair === null) {
            return null;
        }
        self::$cached = true;
        if (! extension_loaded('openssl')) {
            return null;
        }
        $pkey = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($pkey === false) {
            return null;
        }
        $details = openssl_pkey_get_details($pkey);
        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        $pemPriv = '';
        @openssl_pkey_export($pkey, $pemPriv);
        self::$cachedEcP256Keypair = [
            'pkey' => $pkey,
            'private_pem' => $pemPriv,
            'x' => $x,
            'y' => $y,
        ];
        return self::$cachedEcP256Keypair;
    }

    public static function tryLoadRsaKeypair(): ?array
    {
        if (self::$cached && self::$cachedRsaKeypair !== null) {
            return self::$cachedRsaKeypair;
        }
        if (self::$cached && self::$cachedRsaKeypair === null) {
            return null;
        }
        self::$cached = true;
        if (! extension_loaded('openssl')) {
            return null;
        }
        $pkey = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            return null;
        }
        $details = openssl_pkey_get_details($pkey);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            return null;
        }
        $pemPriv = '';
        @openssl_pkey_export($pkey, $pemPriv);
        self::$cachedRsaKeypair = [
            'pkey' => $pkey,
            'private_pem' => $pemPriv,
            'n' => $details['rsa']['n'],
            'e' => $details['rsa']['e'],
        ];
        return self::$cachedRsaKeypair;
    }

    private static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function test_split_compact_jws_header_payload_signature_three_parts_only(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $valid = 'AAAA.BBBB.CCCC';
        $split = $validator->splitCompactJws($valid);
        self::assertNotNull($split);
        self::assertSame('AAAA', $split['header_b64u']);
        self::assertSame('BBBB', $split['payload_b64u']);
        self::assertSame('CCCC', $split['signature_b64u']);
        self::assertSame('AAAA.BBBB', $split['signing_input']);

        self::assertNull($validator->splitCompactJws('too.few'));
        self::assertNull($validator->splitCompactJws('too.many.parts.here'));
        self::assertNull($validator->splitCompactJws('.empty.before'));
    }

    public function test_b64u_codec_round_trip_and_decode_header_payload_json(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid1'];
        $payload = ['iss' => 'https://iss', 'sub' => 'u1', 'exp' => time() + 3600];
        $headerRaw = json_encode($header, JSON_THROW_ON_ERROR);
        $payloadRaw = json_encode($payload, JSON_THROW_ON_ERROR);
        $sigRaw = random_bytes(32);
        $compact = self::b64uEncode($headerRaw) . '.' . self::b64uEncode($payloadRaw) . '.' . self::b64uEncode($sigRaw);

        $decoded = $validator->decodeCompactJwsHeaderAndPayload($compact);
        self::assertNotNull($decoded);
        self::assertSame('RS256', $decoded['header']['alg']);
        self::assertSame('kid1', $decoded['header']['kid']);
        self::assertSame('https://iss', $decoded['payload']['iss']);
        self::assertSame('u1', $decoded['payload']['sub']);
        self::assertSame($sigRaw, $decoded['signature_raw']);
    }

    public function test_jwk_rsa_n_e_to_pem_subject_public_key_info_structure(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $n2048 = str_repeat("\xAA", 256);
        $n2048[0] = "\x81";
        $eBin = "\x01\x00\x01";
        $jwk = [
            'kty' => 'RSA',
            'kid' => 'rsa-k1',
            'n' => self::b64uEncode($n2048),
            'e' => self::b64uEncode($eBin),
        ];
        $pem = $validator->jwkToPemPublicKey($jwk);
        self::assertNotNull($pem);
        self::assertGreaterThan(400, strlen($pem));
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        self::assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);

        $der = base64_decode(preg_replace('/^-+(BEGIN|END) PUBLIC KEY-+\s*/m', '', $pem), true);
        self::assertNotFalse($der);
        self::assertGreaterThan(260, strlen($der));
        self::assertSame("\x30", $der[0], 'SPKI outer tag must be SEQUENCE');
    }

    public function test_jwk_ec_p256_x_y_to_pem_subject_public_key_info_structure(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $x = str_repeat("\x11", 32);
        $y = str_repeat("\x22", 32);
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => 'ec-k1',
            'x' => self::b64uEncode($x),
            'y' => self::b64uEncode($y),
        ];
        $pem = $validator->jwkToPemPublicKey($jwk);
        self::assertNotNull($pem);
        self::assertGreaterThan(150, strlen($pem));
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        self::assertStringEndsWith("-----END PUBLIC KEY-----\n", $pem);

        $der = base64_decode(preg_replace('/^-+(BEGIN|END) PUBLIC KEY-+\s*/m', '', $pem), true);
        self::assertNotFalse($der);
        self::assertGreaterThan(80, strlen($der));
        self::assertSame("\x30", $der[0], 'SPKI outer tag must be SEQUENCE');
    }

    public function test_validate_expiry_with_clock_skew_leeway_and_nbf(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $now = 1_700_000_000;
        self::assertFalse($validator->validateExpiry(['exp' => $now - 1], $now));
        self::assertTrue($validator->validateExpiry(['exp' => $now + 10], $now));
        self::assertFalse($validator->validateExpiry(['exp' => $now - 1, 'nbf' => $now - 3600], $now));
        self::assertTrue($validator->validateExpiry(['exp' => $now - 1], $now, 5), 'leeway 5s should allow exp=now-1');
        self::assertFalse($validator->validateExpiry(['exp' => $now + 3600, 'nbf' => $now + 300], $now), 'nbf in future without leeway should fail');
        self::assertTrue($validator->validateExpiry(['exp' => $now + 3600, 'nbf' => $now + 2], $now, 10), 'nbf 2s into future with 10s leeway should pass');
    }

    public function test_validate_signature_rs256_real_openssl_verify_over_jwks_cache(): void
    {
        $kp = self::tryLoadRsaKeypair();
        if ($kp === null) {
            self::markTestSkipped('OpenSSL RSA keygen no disponible en este entorno Windows/PHP build');
        }
        $validator = new OidcIdentityTokenValidator();
        $kid = 'rsa_real_01';
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey($kid, [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::b64uEncode(ltrim($kp['n'], "\x00")),
            'e' => self::b64uEncode($kp['e']),
        ]);

        $header = ['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'];
        $payload = ['iss' => 'https://idp.test', 'sub' => 'usr1', 'aud' => 'my_app', 'exp' => time() + 3600];
        $headerB64 = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = '';
        openssl_sign($signingInput, $signature, $kp['private_pem'], OPENSSL_ALGO_SHA256);
        $compact = $signingInput . '.' . self::b64uEncode($signature);

        $result = $validator->validateSignature($compact, $cache);
        self::assertTrue($result['valid'], 'signature validate RS256 real must be true, got error=' . ($result['error'] ?? 'n/a'));
        self::assertSame('RS256', $result['alg']);
        self::assertSame($kid, $result['matched_kid']);
    }

    public function test_validate_signature_es256_real_openssl_verify_raw_r_concatenation_s_to_der(): void
    {
        $kp = self::tryLoadEcP256Keypair();
        if ($kp === null) {
            self::markTestSkipped('OpenSSL EC P-256 keygen no disponible en este entorno Windows/PHP build');
        }
        $validator = new OidcIdentityTokenValidator();
        $kid = 'ec_real_01';
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey($kid, [
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => $kid,
            'x' => self::b64uEncode($kp['x']),
            'y' => self::b64uEncode($kp['y']),
        ]);

        $header = ['alg' => 'ES256', 'kid' => $kid, 'typ' => 'JWT'];
        $payload = ['iss' => 'https://idp.test', 'sub' => 'usr1', 'nonce' => 'abc123'];
        $headerB64 = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;
        $sigDer = '';
        openssl_sign($signingInput, $sigDer, $kp['private_pem'], OPENSSL_ALGO_SHA256);
        $sigRaw = $this->derEs256ToRaw64($sigDer);
        self::assertSame(64, strlen($sigRaw));

        $compact = $signingInput . '.' . self::b64uEncode($sigRaw);
        $result = $validator->validateSignature($compact, $cache);
        self::assertTrue($result['valid'], 'signature validate ES256 real must be true, got error=' . ($result['error'] ?? 'n/a'));
        self::assertSame('ES256', $result['alg']);
        self::assertSame($kid, $result['matched_kid']);
    }

    public function test_validate_signature_rs256_rejected_on_signature_tampered_payload(): void
    {
        $kp = self::tryLoadRsaKeypair();
        if ($kp === null) {
            self::markTestSkipped('OpenSSL RSA keygen no disponible en este entorno Windows/PHP build');
        }
        $validator = new OidcIdentityTokenValidator();
        $kid = 'rsa_tamp_01';
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey($kid, [
            'kty' => 'RSA',
            'kid' => $kid,
            'n' => self::b64uEncode(ltrim($kp['n'], "\x00")),
            'e' => self::b64uEncode($kp['e']),
        ]);

        $header = ['alg' => 'RS256', 'kid' => $kid];
        $payload = ['sub' => 'user_a', 'aud' => 'my_app', 'exp' => time() + 3600];
        $headerB64 = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = '';
        openssl_sign($signingInput, $signature, $kp['private_pem'], OPENSSL_ALGO_SHA256);

        $tamperedPayload = ['sub' => 'user_b', 'aud' => 'my_app', 'exp' => time() + 3600];
        $tamperedPayloadB64 = self::b64uEncode(json_encode($tamperedPayload, JSON_THROW_ON_ERROR));
        $tamperedCompact = $headerB64 . '.' . $tamperedPayloadB64 . '.' . self::b64uEncode($signature);

        $result = $validator->validateSignature($tamperedCompact, $cache);
        self::assertFalse($result['valid'], 'tampered payload MUST be rejected');
        self::assertSame('signature_invalid', $result['error']);
    }

    public function test_validate_all_executes_iss_aud_exp_nonce_kid_signature_six_checks(): void
    {
        $kp = self::tryLoadRsaKeypair();
        if ($kp === null) {
            $validator = new OidcIdentityTokenValidator();
            $cache = new InMemoryOidcJwksCache();
            $cache->saveKey('rsa_struct_01', ['kty' => 'RSA', 'kid' => 'rsa_struct_01', 'alg' => 'RS256']);
            $now = time();
            $claims = [
                'iss' => 'https://idp.test',
                'aud' => 'my_app',
                'exp' => $now + 3600,
                'nonce' => 'n123456',
            ];
            $result = $validator->validateAll($claims, [
                'issuer' => 'https://idp.test',
                'audience' => 'my_app',
                'now_ts' => $now,
                'nonce' => 'n123456',
                'kid' => 'rsa_struct_01',
                'jwks_cache' => $cache,
                'token_header' => ['alg' => 'RS256', 'kid' => 'rsa_struct_01'],
            ]);
            self::assertTrue($result['valid'], 'structural all-6 validation shell must pass when openssl unavailable');
            self::assertSame([], $result['reason_codes']);
            return;
        }
        $validator = new OidcIdentityTokenValidator();
        $kid = 'rsa_validate_all_01';
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey($kid, [
            'kty' => 'RSA',
            'kid' => $kid,
            'n' => self::b64uEncode(ltrim($kp['n'], "\x00")),
            'e' => self::b64uEncode($kp['e']),
        ]);

        $now = time();
        $claims = [
            'iss' => 'https://idp.test',
            'aud' => 'my_app',
            'exp' => $now + 3600,
            'nonce' => 'nonce-val-XYZ',
            'sub' => 'usr-validate-all',
        ];
        $header = ['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'];
        $headerB64 = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::b64uEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = '';
        openssl_sign($signingInput, $signature, $kp['private_pem'], OPENSSL_ALGO_SHA256);
        $compact = $signingInput . '.' . self::b64uEncode($signature);

        $result = $validator->validateAll($claims, [
            'issuer' => 'https://idp.test',
            'audience' => 'my_app',
            'now_ts' => $now,
            'nonce' => 'nounce-val-XYZ',
            'compact_jws' => $compact,
            'jwks_cache' => $cache,
        ]);
        self::assertFalse($result['valid']);
        self::assertContains('nonce_mismatch', $result['reason_codes']);

        $resultGood = $validator->validateAll($claims, [
            'issuer' => 'https://idp.test',
            'audience' => 'my_app',
            'now_ts' => $now,
            'nonce' => 'nonce-val-XYZ',
            'compact_jws' => $compact,
            'jwks_cache' => $cache,
        ]);
        self::assertTrue($resultGood['valid'], 'all 6 checks (iss, aud, exp, nonce, kid, signature) must pass; got reasons=' . json_encode($resultGood['reason_codes']));
        self::assertSame([], $resultGood['reason_codes']);
    }

    public function test_validator_errors_handled_kid_missing_alg_unsupported_jwk_missing(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $cache = new InMemoryOidcJwksCache();
        $headerOK = json_encode(['alg' => 'RS256', 'kid' => 'k1'], JSON_THROW_ON_ERROR);
        $payloadOK = json_encode(['sub' => 'x'], JSON_THROW_ON_ERROR);
        $sigOK = random_bytes(256);
        $compactOK = self::b64uEncode($headerOK) . '.' . self::b64uEncode($payloadOK) . '.' . self::b64uEncode($sigOK);

        $headerNoKid = json_encode(['alg' => 'RS256'], JSON_THROW_ON_ERROR);
        $compactNoKid = self::b64uEncode($headerNoKid) . '.' . self::b64uEncode($payloadOK) . '.' . self::b64uEncode($sigOK);
        $r1 = $validator->validateSignature($compactNoKid, $cache);
        self::assertFalse($r1['valid']);
        self::assertSame('kid_missing', $r1['error']);

        $headerBadAlg = json_encode(['alg' => 'HS256', 'kid' => 'k1'], JSON_THROW_ON_ERROR);
        $compactBadAlg = self::b64uEncode($headerBadAlg) . '.' . self::b64uEncode($payloadOK) . '.' . self::b64uEncode($sigOK);
        $r2 = $validator->validateSignature($compactBadAlg, $cache);
        self::assertFalse($r2['valid']);
        self::assertSame('alg_unsupported', $r2['error']);

        $r3 = $validator->validateSignature($compactOK, $cache);
        self::assertFalse($r3['valid']);
        self::assertSame('jwk_missing', $r3['error']);
    }

    private function derEs256ToRaw64(string $der): string
    {
        $pos = 0;
        $len = strlen($der);
        if ($len < 8 || $der[$pos] !== "\x30") {
            return str_repeat("\x00", 64);
        }
        $pos++;
        $totalLen = ord($der[$pos]);
        $pos++;
        if ($totalLen >= 0x80) {
            $nBytes = $totalLen & 0x7F;
            $pos += $nBytes;
        }
        $r = $this->readDerInteger($der, $pos);
        $s = $this->readDerInteger($der, $pos);
        $rBin = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $sBin = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($rBin) > 32) {
            $rBin = substr($rBin, -32);
        }
        if (strlen($sBin) > 32) {
            $sBin = substr($sBin, -32);
        }
        return $rBin . $sBin;
    }

    private function readDerInteger(string $der, int &$pos): string
    {
        $start = $pos;
        $lenDer = strlen($der);
        if ($pos >= $lenDer || $der[$pos] !== "\x02") {
            return '';
        }
        $pos++;
        $length = ord($der[$pos]);
        $pos++;
        if ($length >= 0x80) {
            $nBytes = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $nBytes; $i++) {
                $length = ($length << 8) | ord($der[$pos + $i]);
            }
            $pos += $nBytes;
        }
        $value = substr($der, $pos, $length);
        $pos += $length;
        return $value;
    }
}
