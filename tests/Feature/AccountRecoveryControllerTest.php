<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Controllers\AccountRecoveryController;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Auth\Devices\TrustedDevicePublicId;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionId;
use Quantum\Auth\Tokens\OpaqueAccessToken;
use Quantum\Auth\Tokens\OpaqueRefreshToken;
use Quantum\Auth\Tokens\TokenId;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class AccountRecoveryControllerTest extends TestCase
{
    public function test_password_reset_http_flow_issues_preview_token_and_completes_once(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-account-recovery-' . uniqid('', true);
        if (! mkdir($concurrentDirectory = $basePath, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $basePath));
        }

        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.recovery.password_reset.expose_token', true);
        $config->set('auth.passkeys.enabled', true);
        $config->set('auth.passkeys.store.driver', 'file');
        $config->set('auth.providers.local.identities', [
            [
                'id' => 7301,
                'identifier' => 'http-recovery@example.com',
                'password_hash' => password_hash('http-old-secret', PASSWORD_DEFAULT),
                'type' => 'user',
            ],
        ]);

        $router = $app->make(Router::class);
        $router->post('/auth/recovery/password-reset/request', [AccountRecoveryController::class, 'requestPasswordReset'])->middleware('guest');
        $router->post('/auth/recovery/password-reset/complete', [AccountRecoveryController::class, 'resetPassword'])->middleware('guest');

        $startResponse = $app->make(HttpKernel::class)->handle(Request::create(
            '/auth/recovery/password-reset/request',
            'POST',
            request: ['identifier' => 'http-recovery@example.com'],
            server: ['HTTP_USER_AGENT' => 'VoltStack Recovery Test', 'REMOTE_ADDR' => '127.0.0.10'],
        ));

        self::assertSame(202, $startResponse->statusCode());
        self::assertSame('accepted', $startResponse->headers()['X-Auth-Recovery-Status'] ?? null);

        /** @var array<string, mixed> $startPayload */
        $startPayload = json_decode($startResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        $token = $startPayload['token'] ?? null;

        self::assertIsString($token);
        self::assertNotSame('', trim($token));

        $identity = $app->make(IdentityProviderInterface::class)->findByIdentifier('http-recovery@example.com');
        self::assertNotNull($identity);

        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $opaqueTokens = $app->make(OpaqueTokenRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $passkeys = $app->make(PasskeyCredentialStoreInterface::class);
        $reference = new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type());

        $sessions->save(new AuthenticationSession(
            id: new AuthenticationSessionId('sess-http-recovery-1'),
            identity: $identity,
            reference: $reference,
            method: 'password',
            issuedAt: time(),
            expiresAt: time() + 3600,
            attributes: ['session_public_id' => 'spub-http-recovery-1'],
        ));

        $accessId = new TokenId('access-http-recovery-1');
        $refreshId = new TokenId('refresh-http-recovery-1');
        $opaqueTokens->saveAccessToken(new OpaqueAccessToken(
            id: $accessId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 3600,
            refreshTokenId: $refreshId,
        ));
        $opaqueTokens->saveRefreshToken(new OpaqueRefreshToken(
            id: $refreshId,
            reference: $reference,
            issuedAt: time(),
            expiresAt: time() + 7200,
            accessTokenId: $accessId,
        ));
        $trustedDevices->save(new TrustedDevice(
            publicId: new TrustedDevicePublicId('tdv_http_recovery_1'),
            reference: $reference,
            deviceReference: 'device-http-recovery-1',
            issuedAt: time(),
            expiresAt: time() + 7200,
        ));
        self::assertInstanceOf(PasskeyCredentialStoreInterface::class, $passkeys);
        $passkeys->save(new PasskeyCredentialRecord(
            credentialId: 'pk-http-recovery-1',
            credentialPublicKey: 'pubkey-http-1',
            userHandle: 'http-recovery@example.com',
            rpId: 'localhost',
            createdAt: time(),
        ));

        $completeResponse = $app->make(HttpKernel::class)->handle(Request::create(
            '/auth/recovery/password-reset/complete',
            'POST',
            request: [
                'token' => $token,
                'new_password' => 'Http-new-secret-456!',
            ],
            server: ['HTTP_USER_AGENT' => 'VoltStack Recovery Test', 'REMOTE_ADDR' => '127.0.0.10'],
        ));

        self::assertSame(200, $completeResponse->statusCode());
        self::assertSame('completed', $completeResponse->headers()['X-Auth-Recovery-Status'] ?? null);

        /** @var array<string, mixed> $completePayload */
        $completePayload = json_decode($completeResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $completePayload['status'] ?? null);
        self::assertSame(1, $completePayload['sessions_revoked'] ?? null);
        self::assertSame(2, $completePayload['tokens_revoked'] ?? null);
        self::assertSame(1, $completePayload['trusted_devices_revoked'] ?? null);
        self::assertSame(1, $completePayload['passkeys_revoked'] ?? null);
        self::assertCount(0, $sessions->listForIdentity($identity));
        self::assertTrue($opaqueTokens->findAccessToken('access-http-recovery-1')?->revoked ?? false);
        self::assertTrue($opaqueTokens->findRefreshToken('refresh-http-recovery-1')?->revoked ?? false);
        self::assertCount(0, $trustedDevices->listForIdentity($reference));
        self::assertSame([], $passkeys->listForUserHandle('http-recovery@example.com'));

        $replayResponse = $app->make(HttpKernel::class)->handle(Request::create(
            '/auth/recovery/password-reset/complete',
            'POST',
            request: [
                'token' => $token,
                'new_password' => 'Http-another-secret-789!',
            ],
            server: ['HTTP_USER_AGENT' => 'VoltStack Recovery Test', 'REMOTE_ADDR' => '127.0.0.10'],
        ));

        self::assertSame(400, $replayResponse->statusCode());
        self::assertSame('invalid', $replayResponse->headers()['X-Auth-Recovery-Token'] ?? null);
    }
}
