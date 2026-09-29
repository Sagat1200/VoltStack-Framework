<?php

declare(strict_types=1);

namespace Quantum\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\OidcAuthenticator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Federation\Oidc\FileOidcJwksCache;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentitySecurityState;
use Quantum\Auth\Runtime\AuthenticationOperationContext;

final class Bloque2OidcCryptoTest extends TestCase
{
    private static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function makeCompactJws(array $header, array $payload, string $signature = 'FAKESIG'): string
    {
        $h = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $p = self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $s = self::b64uEncode($signature);
        return $h . '.' . $p . '.' . $s;
    }

    private static function tryLoadRsaKeypair(): ?array
    {
        if (! extension_loaded('openssl')) {
            return null;
        }
        $pkey = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($pkey === false) {
            return null;
        }
        $details = openssl_pkey_get_details($pkey);
        if ($details === false) {
            return null;
        }
        $n = $details['rsa']['n'] ?? '';
        $e = $details['rsa']['e'] ?? '';
        if ($n === '' || $e === '') {
            return null;
        }
        return [
            'pkey' => $pkey,
            'n_raw' => $n,
            'e_raw' => $e,
        ];
    }

    public function test_b2_01_split_compact_jws_3parts(): void
    {
        $v = new OidcIdentityTokenValidator();
        $header = ['alg' => 'RS256', 'kid' => 'k1'];
        $payload = ['sub' => 'u1', 'iss' => 'https://example.com'];
        $jws = self::makeCompactJws($header, $payload);
        $split = $v->splitCompactJws($jws);
        $this->assertNotNull($split);
        $this->assertArrayHasKey('signing_input', $split);
        $parts = explode('.', $jws);
        $this->assertSame($parts[0] . '.' . $parts[1], $split['signing_input']);

        $this->assertNull($v->splitCompactJws("only.two"));
        $this->assertNull($v->splitCompactJws(".."));
    }

    public function test_b2_02_jose_parse_header_payload_ok(): void
    {
        $v = new OidcIdentityTokenValidator();
        $header = ['alg' => 'RS256', 'kid' => 'my-kid-02'];
        $payload = [
            'sub' => 'user-xyz-02',
            'iss' => 'https://id.example.co',
            'aud' => 'client-aud-02',
            'exp' => 1700000000,
        ];
        $jws = self::makeCompactJws($header, $payload, 'DUMMYSIG');
        $decoded = $v->decodeCompactJwsHeaderAndPayload($jws);
        $this->assertNotNull($decoded);
        $this->assertSame('RS256', $decoded['header']['alg']);
        $this->assertSame('my-kid-02', $decoded['header']['kid']);
        $this->assertSame('user-xyz-02', $decoded['payload']['sub']);
        $this->assertSame('https://id.example.co', $decoded['payload']['iss']);
    }

    public function test_b2_03_b64u_roundtrip_codec(): void
    {
        $cases = [
            'hello world',
            str_repeat("\x00\x01\x02\x03", 8),
            random_bytes(33),
            '',
        ];
        foreach ($cases as $i => $raw) {
            $enc = OidcIdentityTokenValidator::b64uEncode($raw);
            $this->assertStringNotContainsString('+', $enc, 'case ' . $i . ' no plus');
            $this->assertStringNotContainsString('/', $enc, 'case ' . $i . ' no slash');
            $this->assertStringNotContainsString('=', $enc, 'case ' . $i . ' no equals');
        }
        $this->addToAssertionCount(1);
    }

    public function test_b2_04_validate_issuer_trailing_slash_tolerant(): void
    {
        $v = new OidcIdentityTokenValidator();
        $this->assertTrue($v->validateIssuer(['iss' => 'https://a.example/'], 'https://a.example'));
        $this->assertTrue($v->validateIssuer(['iss' => 'https://a.example'], 'https://a.example/'));
        $this->assertTrue($v->validateIssuer(['iss' => 'https://a.example'], 'https://a.example'));
        $this->assertFalse($v->validateIssuer(['iss' => 'https://b.example'], 'https://other.example'));
        $this->assertFalse($v->validateIssuer(['sub' => 'only'], 'https://a.example'));
    }

    public function test_b2_05_validate_audience_multi_overlap(): void
    {
        $v = new OidcIdentityTokenValidator();
        $audSingle = ['aud' => 'client-a'];
        $audList = ['sub' => 'x', 'aud' => ['client-a', 'client-b']];
        $audMulti = ['aud' => ['client-x', 'client-y']];
        $audEmpty = ['sub' => 'x'];
        $this->assertTrue($v->validateAudience($audSingle, 'client-a'));
        $this->assertTrue($v->validateAudience($audList, ['client-b', 'client-z']));
        $this->assertTrue($v->validateAudience($audList, 'client-b'));
        $this->assertFalse($v->validateAudience($audMulti, ['client-a', 'client-c']));
        $this->assertFalse($v->validateAudience($audEmpty, 'client-a'));
        $this->assertFalse($v->validateAudience($audSingle, ['client-b', 'client-c']));
    }

    public function test_b2_06_validate_expiry_leeway_clock_skew_passes(): void
    {
        $v = new OidcIdentityTokenValidator();
        $now = 1000;
        $expAtEdge = ['exp' => 1005, 'nbf' => 995];
        $this->assertTrue($v->validateExpiry($expAtEdge, $now, 10));
        $this->assertFalse($v->validateExpiry($expAtEdge, 1020));
        $this->assertFalse($v->validateExpiry(['nbf' => 900], $now));
        $nbfFuture = ['exp' => 2000, 'nbf' => 1500];
        $this->assertFalse($v->validateExpiry($nbfFuture, $now, 0));
        $this->assertTrue($v->validateExpiry($nbfFuture, $now, 600));
    }

    public function test_b2_07_jwk_rsa_to_pem_structural(): void
    {
        $v = new OidcIdentityTokenValidator();
        $n = str_repeat("\xaa", 256);
        $e = "\x01\x00\x01";
        $jwk = [
            'kty' => 'RSA',
            'n' => self::b64uEncode($n),
            'e' => self::b64uEncode($e),
        ];
        $pem = $v->jwkToPemPublicKey($jwk);
        $this->assertNotNull($pem);
        $this->assertGreaterThan(200, strlen($pem));
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        $wrongKty = ['kty' => 'OKP', 'x' => self::b64uEncode('abcd')];
        $this->assertNull($v->jwkToPemPublicKey($wrongKty));
    }

    public function test_b2_08_validate_signature_rs256_openssl_real_if_keygen(): void
    {
        $pair = self::tryLoadRsaKeypair();
        if ($pair === null) {
            $this->markTestSkipped('Entorno PHP no permite generar claves RSA 2048 vía openssl_pkey_new');
            return;
        }
        $v = new OidcIdentityTokenValidator();
        $kid = 'rsa-kid-08';
        $header = ['alg' => 'RS256', 'kid' => $kid];
        $payload = ['sub' => 'rs256-user', 'iss' => 'https://op', 'aud' => 'me', 'exp' => time() + 3600];
        $headerB64 = self::b64uEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = self::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = '';
        openssl_sign($signingInput, $signature, $pair['pkey'], OPENSSL_ALGO_SHA256);
        $this->assertNotFalse($signature);
        $compact = $signingInput . '.' . self::b64uEncode($signature);
        $jwk = [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => self::b64uEncode($pair['n_raw']),
            'e' => self::b64uEncode($pair['e_raw']),
        ];
        $cache = new InMemoryOidcJwksCache(0);
        $cache->saveKey($kid, $jwk);

        $good = $v->validateSignature($compact, $cache);
        $this->assertTrue($good['valid']);
        $this->assertSame('RS256', $good['alg']);
        $this->assertSame($kid, $good['matched_kid']);

        $tampered = $signingInput . '.' . self::b64uEncode($signature . 'X');
        $bad = $v->validateSignature($tampered, $cache);
        $this->assertFalse($bad['valid']);
    }

    public function test_b2_09_jwks_inmemory_vs_file_roundtrip_ttl(): void
    {
        $kidA = 'kid-A';
        $kidB = 'kid-B';
        $baseTs = time();
        $jwkA = ['kty' => 'RSA', 'kid' => $kidA, 'n' => self::b64uEncode(str_repeat("\x11", 256)), 'e' => self::b64uEncode("\x01\x00\x01")];
        $jwkB = ['kty' => 'EC', 'kid' => $kidB, 'crv' => 'P-256', 'x' => self::b64uEncode(str_repeat("\x22", 32)), 'y' => self::b64uEncode(str_repeat("\x33", 32))];

        $memNoTtl = new InMemoryOidcJwksCache(0);
        $memNoTtl->saveKey($kidA, $jwkA);
        $memNoTtl->markFetchedNow($baseTs);
        $this->assertSame($jwkA, $memNoTtl->getKey($kidA));
        $this->assertNull($memNoTtl->getKey($kidB));
        $this->assertFalse($memNoTtl->hasExpired($baseTs + 10000));
        $this->assertSame(0, $memNoTtl->getTtlSeconds());

        $memTtl = new InMemoryOidcJwksCache(10);
        $memTtl->markFetchedNow($baseTs);
        $this->assertFalse($memTtl->hasExpired($baseTs + 1));
        $this->assertFalse($memTtl->hasExpired($baseTs + 9));
        $this->assertTrue($memTtl->hasExpired($baseTs + 11));
        $this->assertSame($baseTs, $memTtl->getFetchedAt());
        $memTtl->clear();
        $this->assertSame(0, $memTtl->getFetchedAt());

        $tmpDir = sys_get_temp_dir() . '/b2_oidc_jwks_' . substr(bin2hex(random_bytes(6)), 0, 10);
        @mkdir($tmpDir, 0777, true);
        try {
            $fileA = new FileOidcJwksCache($tmpDir, 20);
            $fileA->saveKey($kidA, $jwkA);
            $fileA->saveKey($kidB, $jwkB);
            $fileA->markFetchedNow($baseTs);

            $fileB = new FileOidcJwksCache($tmpDir, 20);
            $this->assertSame($kidA, $fileB->getKey($kidA)['kid'] ?? null);
            $this->assertSame($kidB, $fileB->getKey($kidB)['kid'] ?? null);
            $this->assertSame($baseTs, $fileB->getFetchedAt());
            $this->assertFalse($fileB->hasExpired($baseTs + 5));
            $this->assertTrue($fileB->hasExpired($baseTs + 25));
            $this->assertSame(20, $fileB->getTtlSeconds());

            $fileB->clear();
            $fileC = new FileOidcJwksCache($tmpDir, 20);
            $this->assertNull($fileC->getKey($kidA));
            $this->assertNull($fileC->getKey($kidB));
            $this->assertSame(0, $fileC->getFetchedAt());
        } finally {
            $files = glob(rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . '*');
            foreach ($files ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($tmpDir);
        }
    }

    public function test_b2_10_oidc_authenticator_supports_and_authenticate_metadata(): void
    {
        $v = new OidcIdentityTokenValidator();
        $now = time();
        $payload = [
            'iss' => 'https://op.test',
            'aud' => 'client-10',
            'sub' => 'usr-10',
            'exp' => $now + 3600,
            'amr' => ['pwd', 'mfa'],
        ];
        $compact = self::makeCompactJws(['alg' => 'none', 'kid' => 'k'], $payload, 'sig');

        $identity = new class($payload['sub']) implements IdentityInterface {
            public function __construct(private readonly string $_id)
            {
            }
            public function identifier(): IdentityIdentifier
            {
                return new IdentityIdentifier($this->_id);
            }
            public function type(): string
            {
                return 'user';
            }
        };

        $idp = new class($identity) implements IdentityProviderInterface {
            public function __construct(private readonly IdentityInterface $_id)
            {
            }
            public function findByIdentifier(string $identifier): ?IdentityInterface
            {
                return $this->_id;
            }
            public function passwordHashFor(IdentityInterface $identity): ?string
            {
                return null;
            }
            public function securityStateFor(IdentityInterface $identity): IdentitySecurityState
            {
                return IdentitySecurityState::Active;
            }
        };

        $auth = new OidcAuthenticator($v, $idp, null, ['issuer' => 'https://op.test', 'audience' => 'client-10']);

        $req1 = new AuthenticationRequest('r10-a', 'runtime', [
            'credentials' => ['mechanism' => 'oidc', 'id_token' => $compact, 'now_ts' => $now],
        ]);
        $op1 = new AuthenticationOperationContext('authenticate', $req1);
        $this->assertTrue($auth->supports($op1));
        $decision1 = $auth->authenticate($op1);
        $this->assertTrue($decision1->isAuthenticated(), print_r($decision1->metadata, true));
        $this->assertSame('oidc', $decision1->metadata['authenticator'] ?? null);
        $this->assertTrue($decision1->metadata['federated'] ?? false);
        $this->assertContains('oidc', $decision1->metadata['amr'] ?? []);
        $this->assertContains('federated', $decision1->metadata['amr'] ?? []);
        $this->assertSame('usr-10', $decision1->metadata['sub'] ?? null);

        $req2 = new AuthenticationRequest('r10-b', 'runtime', ['credentials' => ['password' => 'x']]);
        $op2 = new AuthenticationOperationContext('authenticate', $req2);
        $this->assertFalse($auth->supports($op2));
    }
}
