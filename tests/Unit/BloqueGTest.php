<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Federation\Oidc\FederatedClaimsMapper;
use Quantum\Auth\Federation\Oidc\InMemoryMockOidcWellKnownClient;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Identity\IdentitySecurityState;

final class BloqueGTest extends TestCase
{
    public function test_well_known_mock_returns_metadata_with_issuer_and_endpoints_appended(): void
    {
        $client = new InMemoryMockOidcWellKnownClient();
        $meta = $client->fetchConfiguration('https://sso.example.com/auth/realms/demo/');
        self::assertSame('https://sso.example.com/auth/realms/demo', $meta->issuer);
        self::assertSame('https://sso.example.com/auth/realms/demo/protocol/openid-connect/auth', $meta->authorizationEndpoint);
        self::assertSame('https://sso.example.com/auth/realms/demo/protocol/openid-connect/certs', $meta->jwksUri);
        self::assertContains('RS256', $meta->idTokenSigningAlgValuesSupported);
    }

    public function test_jwks_cache_save_and_get_key_roundtrips_array_payload(): void
    {
        $cache = new InMemoryOidcJwksCache();
        $kid = 'rsa_key_01';
        $jwk = ['kty' => 'RSA', 'kid' => $kid, 'n' => 'abc123', 'e' => 'AQAB'];

        self::assertNull($cache->getKey($kid));
        $cache->saveKey($kid, $jwk);
        $retrieved = $cache->getKey($kid);
        self::assertNotNull($retrieved);
        self::assertSame('RSA', $retrieved['kty']);
        self::assertSame('abc123', $retrieved['n']);
    }

    public function test_validator_validate_issuer_pass_and_fail(): void
    {
        $validator = new OidcIdentityTokenValidator();

        $passClaims = ['iss' => 'https://issuer.example.com/'];
        self::assertTrue($validator->validateIssuer($passClaims, 'https://issuer.example.com'));
        self::assertTrue($validator->validateIssuer($passClaims, 'https://issuer.example.com/'));

        $failClaims = ['iss' => 'https://other.example'];
        self::assertFalse($validator->validateIssuer($failClaims, 'https://issuer.example.com'));
    }

    public function test_validator_validate_audience_pass_and_fail_multi_string(): void
    {
        $validator = new OidcIdentityTokenValidator();

        $multiAud = ['aud' => ['client_web', 'client_mobile']];
        self::assertTrue($validator->validateAudience($multiAud, 'client_web'));
        self::assertTrue($validator->validateAudience($multiAud, ['client_mobile', 'other']));

        $stringAud = ['aud' => 'single_client'];
        self::assertTrue($validator->validateAudience($stringAud, 'single_client'));
        self::assertFalse($validator->validateAudience($stringAud, 'wrong_client'));
    }

    public function test_validator_validate_all_shell_passes_all_six_checks_for_valid_claims(): void
    {
        $validator = new OidcIdentityTokenValidator();
        $cache = new InMemoryOidcJwksCache();
        $cache->saveKey('kid_main', ['kty' => 'EC']);

        $now = time();
        $claims = [
            'iss' => 'https://idp.test',
            'aud' => 'my_app',
            'exp' => $now + 3600,
            'nonce' => 'expected_nonce_xyz',
        ];

        $result = $validator->validateAll($claims, [
            'issuer' => 'https://idp.test',
            'audience' => 'my_app',
            'now_ts' => $now,
            'nonce' => 'expected_nonce_xyz',
            'kid' => 'kid_main',
            'jwks_cache' => $cache,
        ]);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['reason_codes']);
    }

    public function test_federated_claims_mapper_email_verified_true_active_false_suspended(): void
    {
        $mapper = new FederatedClaimsMapper();

        [$activeIdentity, $activeState] = [$mapper->toGenericIdentity([
            'sub' => 'usr_federated_01',
            'iss' => 'https://idp.test',
            'email' => 'verified@idp.test',
            'email_verified' => true,
            'preferred_username' => 'ver_user',
        ])['identity'], $mapper->toGenericIdentity([
            'sub' => 'usr_federated_01',
            'iss' => 'https://idp.test',
            'email' => 'verified@idp.test',
            'email_verified' => true,
        ])['security_state']];

        self::assertSame(IdentitySecurityState::Active, $activeState);

        $suspended = $mapper->toGenericIdentity([
            'sub' => 'usr_federated_02',
            'iss' => 'https://idp.test',
            'email' => 'unverified@idp.test',
            'email_verified' => false,
        ]);
        self::assertSame(IdentitySecurityState::Suspended, $suspended['security_state']);
        self::assertSame('federated_oidc', $suspended['attributes']['identity_type']);
    }
}
