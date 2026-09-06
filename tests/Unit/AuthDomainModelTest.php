<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final class AuthDomainModelTest extends TestCase
{
    public function test_identity_identifier_rejects_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdentityIdentifier('   ');
    }

    public function test_authentication_request_exposes_attributes(): void
    {
        $request = new AuthenticationRequest(
            requestId: 'req-1',
            transport: 'http',
            attributes: ['tenant' => 'acme'],
        );

        self::assertSame('acme', $request->attribute('tenant'));
        self::assertSame('fallback', $request->attribute('missing', 'fallback'));
    }

    public function test_authenticated_decision_contains_context(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('42'),
            type: 'user',
            attributes: ['name' => 'Volt'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-1',
            method: 'password',
            attributes: ['fresh' => true],
        );

        $decision = AuthenticationDecision::authenticated($context, ['source' => 'test']);

        self::assertTrue($decision->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Authenticated, $decision->status);
        self::assertSame('42', (string) $decision->context?->reference->identifier);
        self::assertTrue($decision->context?->attribute('fresh'));
        self::assertSame('test', $decision->metadata['source'] ?? null);
    }

    public function test_authentication_context_exposes_explicit_strength_and_assurance_profile(): void
    {
        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier('84'),
            type: 'user',
            attributes: ['name' => 'Volt MFA'],
        );

        $context = new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-2',
            method: 'password',
            attributes: [
                'authentication_strength' => 'MultiFactor',
                'authentication_assurance_profile' => 'multi_factor',
            ],
        );

        self::assertSame(AuthenticationStrength::MultiFactor, $context->authenticationStrength());
        self::assertSame('multi_factor', $context->authenticationAssuranceProfile());
    }

    public function test_unauthenticated_decision_has_no_context(): void
    {
        $decision = AuthenticationDecision::unauthenticated(['source' => 'none']);

        self::assertFalse($decision->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Unauthenticated, $decision->status);
        self::assertNull($decision->context);
    }
}
