<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\CsrfChallengeBinder;
use Quantum\Auth\Runtime\InMemoryTransactionNonceStore;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;

final class BloqueETest extends TestCase
{
    public function test_issue_nonce_value_is_alphanumeric_32_chars(): void
    {
        $store = new InMemoryTransactionNonceStore();
        $nonce = $store->issueNonce(300);

        self::assertSame(32, strlen($nonce->value));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $nonce->value);
        self::assertSame(time(), $nonce->issuedAt);
        self::assertSame(time() + 300, $nonce->expiresAt);
    }

    public function test_nonce_with_zero_ttl_invalidates_immediately(): void
    {
        $store = new InMemoryTransactionNonceStore();
        $nonce = $store->issueNonce(1);
        sleep(1);
        $result = $store->validateNonce($nonce->value, []);
        self::assertFalse($result->valid);
        self::assertSame('nonce_expired', $result->reasonCode);
    }

    public function test_nonce_binding_claim_mismatch_returns_fail_validation(): void
    {
        $store = new InMemoryTransactionNonceStore();
        $nonce = $store->issueNonce(300, ['identity_id' => 'id_alice', 'device_ref' => 'dev_abc']);

        $badDevice = $store->validateNonce($nonce->value, ['identity_id' => 'id_alice', 'device_ref' => 'dev_WRONG']);
        self::assertFalse($badDevice->valid);
        self::assertSame('nonce_binding_claim_mismatch:device_ref', $badDevice->reasonCode);
    }

    public function test_nonce_binding_claim_exact_match_passes_validation(): void
    {
        $store = new InMemoryTransactionNonceStore();
        $nonce = $store->issueNonce(300, ['identity_id' => 'id_alice', 'device_ref' => 'dev_abc', 'ip_prefix' => '10.0.0']);

        $result = $store->validateNonce($nonce->value, ['identity_id' => 'id_alice', 'device_ref' => 'dev_abc', 'ip_prefix' => '10.0.0']);
        self::assertTrue($result->valid);
        self::assertNull($result->reasonCode);
        self::assertNotNull($result->nonceRecord);
        self::assertSame($nonce->value, $result->nonceRecord->value);
    }

    public function test_csrf_challenge_is_deterministic_for_same_nonce_and_device(): void
    {
        $binder = new CsrfChallengeBinder();
        $nonce = bin2hex(random_bytes(16));
        $dev = 'dev_xyz';

        $c1 = $binder->issueChallenge($nonce, $dev);
        $c2 = $binder->issueChallenge($nonce, $dev);

        self::assertSame($c1, $c2);
        self::assertTrue($binder->verifyChallenge($c1, $nonce, $dev));
    }

    public function test_csrf_challenge_differs_between_distinct_nonces(): void
    {
        $binder = new CsrfChallengeBinder();
        $dev = 'dev_same_device';
        $n1 = bin2hex(random_bytes(16));
        $n2 = bin2hex(random_bytes(16));

        self::assertNotSame(
            $binder->issueChallenge($n1, $dev),
            $binder->issueChallenge($n2, $dev),
        );
    }

    public function test_nonce_is_one_time_use_consumed_after_first_successful_validation(): void
    {
        $store = new InMemoryTransactionNonceStore();
        $nonce = $store->issueNonce(300, []);

        $first = $store->validateNonce($nonce->value, []);
        self::assertTrue($first->valid);

        $second = $store->validateNonce($nonce->value, []);
        self::assertFalse($second->valid);
        self::assertSame('nonce_not_found_or_consumed', $second->reasonCode);
    }

    public function test_orchestrator_without_nonce_store_has_no_next_auth_nonce_metadata_on_auth_success(): void
    {
        $identity = new GenericIdentity(
            new IdentityIdentifier('nonce_baseline_' . bin2hex(random_bytes(4))),
            'user',
            [],
        );
        $authContext = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-nonce-noop',
            method: 'pwd',
        );

        $authenticator = new class($authContext) implements AuthenticatorInterface {
            public function __construct(private readonly AuthenticationContext $ctxToReturn)
            {
            }

            public function supports(AuthenticationOperationContext $context): bool
            {
                return true;
            }

            public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
            {
                return AuthenticationDecision::authenticated(
                    $this->ctxToReturn,
                    ['authenticator' => 'nonce_noop'],
                );
            }
        };

        $resolver = $this->createMock(AuthenticatorResolverInterface::class);
        $resolver->method('resolve')->willReturn([$authenticator]);

        $orchestrator = new AuthenticationOrchestrator($resolver);

        $request = new AuthenticationRequest(
            requestId: 'nonce-noop-' . bin2hex(random_bytes(4)),
            attributes: [
                'credentials' => ['identifier' => 'nonce_clean@test', 'password' => 'Safe-123!'],
                'device_ref' => 'dev_noop',
            ],
        );
        $ctx = new AuthenticationOperationContext('authenticate', $request);
        $decision = $orchestrator->execute($ctx);

        self::assertTrue($decision->isAuthenticated());
        self::assertArrayNotHasKey('next_auth_nonce', $decision->metadata);
        self::assertArrayNotHasKey('csrf_challenge', $decision->metadata);
        self::assertArrayNotHasKey('nonce_reason', $decision->metadata);
    }
}
