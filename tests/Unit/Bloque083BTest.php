<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Federation\Oidc\JoseSimpleParser;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\InMemoryMockOidcWellKnownClient;
use Quantum\Auth\Federation\Oidc\CurlOidcWellKnownClient;
use Quantum\Auth\Federation\Oidc\OpensslJwsSignatureVerifier;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;

/**
 * Bloque 083-P1 B: OIDC Crypto Foundation. 12 tests estructurales y de
 * integración sin openssl_sign (error cases + estructural PEM/JWKS + backward compat).
 */
final class Bloque083BTest extends TestCase
{
    private static function b64uEn(string $raw): string
    {
        return JoseSimpleParser::b64uEncode($raw);
    }

    private static function makeCompactJws(array $header, array $payload, string $sigB64uOrEmpty = 'fake_sig_b64u'): string
    {
        $h = self::b64uEn(json_encode($header, JSON_THROW_ON_ERROR));
        $p = self::b64uEn(json_encode($payload, JSON_THROW_ON_ERROR));
        return $h . '.' . $p . '.' . $sigB64uOrEmpty;
    }

    // ---------- B2 Parser tests ----------

    public function test_jose_simple_parser_split_rs256_ok(): void
    {
        $parser = new JoseSimpleParser();
        $token = self::makeCompactJws(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'], ['sub' => 'u1', 'iss' => 'https://iss']);
        $split = $parser->split($token);
        self::assertNotNull($split);
        self::assertSame(3, count(explode('.', $token)));
        self::assertNotEmpty($split['header_b64u']);
        self::assertNotEmpty($split['payload_b64u']);
        self::assertNotEmpty($split['signature_b64u']);
        self::assertSame($split['header_b64u'] . '.' . $split['payload_b64u'], $split['signing_input']);
    }

    public function test_jose_simple_parser_parse_es256_ok(): void
    {
        $parser = new JoseSimpleParser();
        $header = ['alg' => 'ES256', 'kid' => 'k2'];
        $payload = ['sub' => 'u2', 'iss' => 'https://iss', 'exp' => time() + 60];
        $token = self::makeCompactJws($header, $payload);
        $parsed = $parser->parse($token);
        self::assertNotNull($parsed);
        self::assertSame('ES256', $parsed['header']['alg']);
        self::assertSame('k2', $parsed['header']['kid']);
        self::assertSame('u2', $parsed['payload']['sub']);
        self::assertSame($parser->algOf($parsed['header']), 'ES256');
        self::assertSame($parser->kidOf($parsed['header']), 'k2');
    }

    public function test_jose_simple_parser_malformed_rejected(): void
    {
        $parser = new JoseSimpleParser();
        // 2 parts only
        self::assertNull($parser->split('a.b'));
        // empty parts
        self::assertNull($parser->split('a..c'));
        // invalid b64u non-json header
        $bad = self::b64uEn('not valid json!!!') . '.' . self::b64uEn('{}') . '.' . 's';
        self::assertNull($parser->parse($bad));
    }

    // ---------- B3 JWKS Cache TTL tests ----------

    public function test_jwks_cache_ttl_zero_infinite_marked_fetched(): void
    {
        $cache = new InMemoryOidcJwksCache(0);
        self::assertSame(0, $cache->getTtlSeconds());
        self::assertSame(0, $cache->getFetchedAt());
        $cache->saveKey('k1', ['kty' => 'RSA', 'n' => 'n1', 'e' => 'AQAB']);
        self::assertNotNull($cache->getKey('k1'));
        $cache->markFetchedNow(1000);
        self::assertSame(1000, $cache->getFetchedAt());
        // ttl=0 nunca expira aunque fetchedAt viejo
        self::assertFalse($cache->hasExpired(99999999));
        self::assertNotNull($cache->getKey('k1'));
    }

    public function test_jwks_cache_ttl_positive_expires_after_window(): void
    {
        $cacheHit = new InMemoryOidcJwksCache(3600);
        $cacheHit->saveKey('k2', ['kty' => 'EC', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y']);
        $cacheHit->markFetchedNow();
        $nowHit = $cacheHit->getFetchedAt();
        self::assertFalse($cacheHit->hasExpired($nowHit + 30));
        self::assertNotNull($cacheHit->getKey('k2'));
        self::assertFalse($cacheHit->hasExpired($nowHit + 3599));
        self::assertTrue($cacheHit->hasExpired($nowHit + 3601));

        // cache con fetchedAt MUY antiguo relativo a time() actual: getKey retorna null automaticamente
        $cacheExpired = new InMemoryOidcJwksCache(1);
        $cacheExpired->saveKey('k3', ['kty' => 'RSA', 'n' => 'n', 'e' => 'AQAB']);
        $veryOld = max(time() - 10000, 1);
        $cacheExpired->markFetchedNow($veryOld);
        self::assertTrue($cacheExpired->hasExpired());
        self::assertNull($cacheExpired->getKey('k3'));

        $cacheHit->clear();
        self::assertSame(0, $cacheHit->getFetchedAt());
        self::assertNull($cacheHit->getKey('k2'));
    }

    // ---------- B4 Mock Well Known fetchJwksByUri ----------

    public function test_mock_wellknown_fetch_jwks_default_empty(): void
    {
        $mock = new InMemoryMockOidcWellKnownClient();
        $meta = $mock->fetchConfiguration('https://kc.example/realms/test');
        self::assertSame('https://kc.example/realms/test/protocol/openid-connect/certs', $meta->jwksUri);
        self::assertContains('RS256', $meta->idTokenSigningAlgValuesSupported);
        $jwks = $mock->fetchJwksByUri($meta->jwksUri);
        self::assertSame([], $jwks['keys']);

        $mock->setMockJwks($meta->jwksUri, [
            ['kty' => 'RSA', 'kid' => 'k1', 'n' => 'N', 'e' => 'AQAB'],
            ['kty' => 'EC', 'kid' => 'k2', 'crv' => 'P-256', 'x' => 'X', 'y' => 'Y'],
        ]);
        $jwks2 = $mock->fetchJwksByUri($meta->jwksUri);
        self::assertCount(2, $jwks2['keys']);
        self::assertSame('k2', $jwks2['keys'][1]['kid']);
    }

    // ---------- B5 Curl client disabled throws ----------

    public function test_curl_client_disabled_throws_with_known_message(): void
    {
        $curl = new CurlOidcWellKnownClient(5, false);
        self::assertFalse($curl->isEnabled());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disabled/');
        $curl->fetchConfiguration('https://example.com');
    }

    public function test_curl_client_disabled_fetch_jwks_throws(): void
    {
        $curl = new CurlOidcWellKnownClient(3, false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disabled/');
        $curl->fetchJwksByUri('https://example/certs');
    }

    // ---------- B1/B6 Signature Verifier error cases (no openssl_sign needed) ----------

    public function test_signature_verifier_rejects_malformed_jws(): void
    {
        $cache = new InMemoryOidcJwksCache();
        $verifier = new OpensslJwsSignatureVerifier();
        $res = $verifier->verifyIdTokenSignature('only.two', $cache);
        self::assertFalse($res['valid']);
        self::assertSame('jws_malformed', $res['error']);
    }

    public function test_signature_verifier_rejects_missing_kid(): void
    {
        $cache = new InMemoryOidcJwksCache();
        $verifier = new OpensslJwsSignatureVerifier();
        $token = self::makeCompactJws(['alg' => 'RS256'], ['iss' => 'x']);
        $res = $verifier->verifyIdTokenSignature($token, $cache);
        self::assertFalse($res['valid']);
        self::assertSame('kid_missing', $res['error']);
    }

    public function test_signature_verifier_rejects_unsupported_alg(): void
    {
        $cache = new InMemoryOidcJwksCache();
        $verifier = new OpensslJwsSignatureVerifier();
        $token = self::makeCompactJws(['alg' => 'HS256', 'kid' => 'k1'], ['iss' => 'x']);
        $res = $verifier->verifyIdTokenSignature($token, $cache);
        self::assertFalse($res['valid']);
        self::assertSame('alg_unsupported', $res['error']);
        self::assertSame('HS256', $res['alg']);
    }

    public function test_signature_verifier_rejects_missing_jwk_in_cache(): void
    {
        $cache = new InMemoryOidcJwksCache();
        $verifier = new OpensslJwsSignatureVerifier();
        $token = self::makeCompactJws(['alg' => 'RS256', 'kid' => 'NOEXIST'], ['iss' => 'x']);
        $res = $verifier->verifyIdTokenSignature($token, $cache);
        self::assertFalse($res['valid']);
        self::assertSame('jwk_missing', $res['error']);
        self::assertSame('NOEXIST', $res['matched_kid']);
    }

    // ---------- B6 Validator 7º check + backward compat null verifier ----------

    public function test_validator_backward_compat_null_verifier_then_injected_verifier_routes_through_interface(): void
    {
        // backward compat: sin inject = internal path, jws malformed retorna array conocido
        $vLegacy = new OidcIdentityTokenValidator();
        $cache = new InMemoryOidcJwksCache();
        $rLeg = $vLegacy->validateSignature('bad.jws', $cache);
        self::assertFalse($rLeg['valid']);
        self::assertSame('jws_malformed', $rLeg['error']);

        // inject verifier via DI: delega a interfaz (mock para confirmar routing)
        $fake = new class () implements OidcSignatureVerifierInterface {
            public function verifyIdTokenSignature(string $compactJws, \Quantum\Auth\Contracts\OidcJwksCacheInterface $jwksCache, ?string $overriddenKid = null): array
            {
                return ['valid' => false, 'alg' => null, 'matched_kid' => null, 'error' => 'fake_delegate:' . $compactJws . ':' . ($overriddenKid ?? '-')];
            }
        };
        $vInjected = new OidcIdentityTokenValidator($fake);
        $rInj = $vInjected->validateSignature('tok', $cache, 'kX');
        self::assertSame('fake_delegate:tok:kX', $rInj['error']);
    }

    public function test_validator_validate_all_seven_checks_with_signature_jwk_present_path(): void
    {
        $v = new OidcIdentityTokenValidator();
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey('k1', ['kty' => 'RSA', 'n' => 'N', 'e' => 'AQAB']);
        $claims = [
            'iss' => 'https://iss',
            'aud' => ['client1', 'client2'],
            'exp' => time() + 60,
            'nonce' => 'NONCE_XYZ',
        ];
        $result = $v->validateAll($claims, [
            'issuer' => 'https://iss',
            'audience' => 'client2',
            'now_ts' => time(),
            'nonce' => 'NONCE_XYZ',
            'jwks_cache' => $cache,
            'token_header' => ['kid' => 'k1', 'alg' => 'RS256'],
        ]);
        // signature_jwk_missing NO porque kid k1 exists; 6 checks OK
        self::assertTrue($result['valid'], print_r($result, true));
        self::assertSame([], $result['reason_codes']);
    }
}
