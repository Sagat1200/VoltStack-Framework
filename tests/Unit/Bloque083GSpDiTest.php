<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AuthenticationServiceProvider;
use Quantum\Auth\Contracts\PasskeyCryptoVerifierInterface;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;
use Quantum\Auth\Passkeys\CoseOpensslCryptoVerifier;
use Quantum\Auth\Federation\Oidc\OpensslJwsSignatureVerifier;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

/**
 * Bloque 083-P1 G(P1): Service Provider 2 bindings PasskeyCryptoVerifierInterface +
 * OidcSignatureVerifierInterface con regla scoped DIRECTO SIN bound() check, default
 * disabled retorna null, enabled retorna implementación openssl naive.
 */
final class Bloque083GSpDiTest extends TestCase
{
    private static function makeAppWithAuthConfig(array $authConfigOverrides = []): Application
    {
        // App skeleton minimalista; vendor/voltstack/framework/voltstack está en Working directories padre.
        $dir = dirname(__DIR__, 3);
        $app = new Application($dir);
        $app->instance(ConfigRepository::class, new ConfigRepository([
            'auth' => array_replace([
                'session' => ['driver' => 'memory'],
                'tokens' => ['driver' => 'memory'],
                'throttle' => ['enabled' => false],
                'risk' => ['enabled' => false],
                'transaction' => ['nonce' => ['enabled' => false]],
                'passkeys' => ['crypto' => ['enabled' => false]],
                'oidc' => ['signature' => ['enabled' => false]],
            ], $authConfigOverrides),
        ]));
        $app->register(AuthenticationServiceProvider::class);
        return $app;
    }

    public function test_passkey_crypto_verifier_default_disabled_resolves_to_null(): void
    {
        $app = self::makeAppWithAuthConfig();
        $resolved = $app->make(PasskeyCryptoVerifierInterface::class);
        self::assertNull($resolved, 'Default auth.passkeys.crypto.enabled=false debe retornar null (opt-in)');
    }

    public function test_oidc_signature_verifier_default_disabled_resolves_to_null(): void
    {
        $app = self::makeAppWithAuthConfig();
        $resolved = $app->make(OidcSignatureVerifierInterface::class);
        self::assertNull($resolved, 'Default auth.oidc.signature.enabled=false debe retornar null (opt-in)');
    }

    public function test_passkey_crypto_verifier_enabled_resolves_to_cose_openssl_impl(): void
    {
        $app = self::makeAppWithAuthConfig([
            'passkeys' => ['crypto' => ['enabled' => true]],
        ]);
        $resolved = $app->make(PasskeyCryptoVerifierInterface::class);
        self::assertNotNull($resolved);
        self::assertInstanceOf(CoseOpensslCryptoVerifier::class, $resolved);
        // Scoped singleton-ish: segunda resolve misma instancia
        $resolved2 = $app->make(PasskeyCryptoVerifierInterface::class);
        self::assertSame($resolved, $resolved2);
    }

    public function test_oidc_signature_verifier_enabled_resolves_to_openssl_jws_impl(): void
    {
        $app = self::makeAppWithAuthConfig([
            'oidc' => ['signature' => ['enabled' => true]],
        ]);
        $resolved = $app->make(OidcSignatureVerifierInterface::class);
        self::assertNotNull($resolved);
        self::assertInstanceOf(OpensslJwsSignatureVerifier::class, $resolved);
        $resolved2 = $app->make(OidcSignatureVerifierInterface::class);
        self::assertSame($resolved, $resolved2);
    }
}
