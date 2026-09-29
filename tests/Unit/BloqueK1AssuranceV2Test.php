<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Exceptions\AssuranceInsufficientException;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final class BloqueK1AssuranceV2Test extends TestCase
{
    private static function makeEmptyOrchestrator(): AuthenticationOrchestrator
    {
        $resolver = new class implements AuthenticatorResolverInterface {
            public function resolve(AuthenticationOperationContext $context): array
            {
                return [];
            }
        };
        return new AuthenticationOrchestrator($resolver);
    }

    private static function makeCurrentContextWithStrength(AuthenticationStrength $strength, array $attributes = []): AuthenticationContext
    {
        $id = new GenericIdentity(new IdentityIdentifier('usr_assurance_01'), 'local', $attributes);
        $ref = new IdentityReference($id->identifier(), $id->type());
        $ctx = new AuthenticationContext(
            identity: $id,
            reference: $ref,
            requestId: 'assure-ctx',
            method: match ($strength) {
                AuthenticationStrength::Anonymous => 'anon',
                AuthenticationStrength::Password => 'password',
                AuthenticationStrength::Token => 'bearer',
                AuthenticationStrength::MultiFactor => 'mfa',
                AuthenticationStrength::HardwareBacked => 'passkey',
            },
            attributes: $attributes,
        );
        return $ctx;
    }

    public function test_assurance_insufficient_exception_is_auth_exception_with_required_and_current_values(): void
    {
        $e = new AssuranceInsufficientException(
            requiredMinAssurance: 500,
            currentAssurance: 100,
            operation: 'delete_account',
        );
        self::assertSame('auth.assurance_insufficient', $e->reasonCode);
        self::assertSame(500, $e->requiredMinAssurance);
        self::assertSame(100, $e->currentAssurance);
        self::assertSame('delete_account', $e->operation);
    }

    public function test_auth_exception_mapper_maps_assurance_insufficient_to_423_with_x_auth_assurance_headers(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new AssuranceInsufficientException(
            requiredMinAssurance: 750,
            currentAssurance: 300,
            operation: 'transfer_funds',
        );
        self::assertSame(423, $mapper->statusCode($e));
        $headers = $mapper->headers($e);
        self::assertSame('true', $headers['X-Auth-Assurance-Insufficient'] ?? null);
        self::assertSame('750', $headers['X-Auth-Assurance-Required-Min'] ?? null);
        self::assertSame('300', $headers['X-Auth-Assurance-Current'] ?? null);
        self::assertSame('transfer_funds', $headers['X-Auth-Operation'] ?? null);
        $jsonExt = $mapper->jsonExtensions($e, false);
        self::assertSame('auth.assurance_insufficient', $jsonExt['reason_code'] ?? null);
        self::assertSame('750', $jsonExt['required_min_assurance'] ?? null);
        self::assertSame('300', $jsonExt['current_assurance'] ?? null);
        self::assertSame('transfer_funds', $jsonExt['operation'] ?? null);
    }

    public function test_orchestrator_returns_assurance_insufficient_rejected_when_min_assurance_attr_set_and_current_lower(): void
    {
        $orch = self::makeEmptyOrchestrator();
        $request = new AuthenticationRequest(
            requestId: 'assure-test-001',
            attributes: ['min_authentication_assurance' => 500],
        );
        $ctx = new AuthenticationOperationContext(operation: 'sensitive_read', request: $request);
        $decision = $orch->execute($ctx);

        self::assertFalse($decision->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Rejected, $decision->status);
        self::assertSame('auth.assurance_insufficient', $decision->metadata['reason'] ?? null);
        self::assertSame(500, $decision->metadata['required_min_assurance'] ?? null);
        self::assertSame(0, $decision->metadata['current_assurance'] ?? null, 'no current context => current assurance = 0');
        self::assertSame('orchestrator_preauth_min_assurance', $decision->metadata['source'] ?? null);
    }

    public function test_orchestrator_passes_when_current_context_assurance_meets_min_threshold(): void
    {
        $orch = self::makeEmptyOrchestrator();
        $hwCtx = self::makeCurrentContextWithStrength(
            AuthenticationStrength::HardwareBacked,
            [
                'assurance_value' => 40,
                'assurance_name' => 'hardware_passkey',
            ],
        );

        $request = new AuthenticationRequest(
            requestId: 'assure-test-002',
            attributes: ['min_authentication_assurance' => 40],
        );
        $ctx = new AuthenticationOperationContext(operation: 'mfa_operation', request: $request, currentContext: $hwCtx);
        $decision = $orch->execute($ctx);
        self::assertNotSame('auth.assurance_insufficient', $decision->metadata['reason'] ?? null, 'HardwareBacked assurance_value=40 meets min 40 threshold');
    }

    public function test_min_assurance_hook_respects_boundary_and_string_coercion(): void
    {
        $orch = self::makeEmptyOrchestrator();
        $requestBoundary = new AuthenticationRequest(
            requestId: 'assure-test-003',
            attributes: ['min_authentication_assurance' => '300'],
        );
        $ctxBound = new AuthenticationOperationContext(operation: 'op', request: $requestBoundary);
        $d1 = $orch->execute($ctxBound);
        self::assertSame('auth.assurance_insufficient', $d1->metadata['reason'] ?? null, 'numeric string min assurance must be coerced');

        $requestMinZero = new AuthenticationRequest(
            requestId: 'assure-test-004',
            attributes: ['min_authentication_assurance' => 0],
        );
        $ctxZero = new AuthenticationOperationContext(operation: 'op', request: $requestMinZero);
        $d2 = $orch->execute($ctxZero);
        self::assertNotSame('auth.assurance_insufficient', $d2->metadata['reason'] ?? null, 'min_assurance=0 disables hook (no threshold)');
    }

    public function test_authentication_decision_rejected_carries_assurance_insufficient_metadata(): void
    {
        $rejected = AuthenticationDecision::rejected([
            'reason' => 'auth.assurance_insufficient',
            'required_min_assurance' => 500,
            'current_assurance' => 100,
            'source' => 'orchestrator_preauth_min_assurance',
        ]);
        self::assertFalse($rejected->isAuthenticated());
        self::assertSame(AuthenticationDecisionStatus::Rejected, $rejected->status);
        self::assertSame('orchestrator_preauth_min_assurance', $rejected->metadata['source']);
        self::assertSame(500, $rejected->metadata['required_min_assurance']);
        self::assertSame(100, $rejected->metadata['current_assurance']);
    }
}
