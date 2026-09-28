<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Bridges\ControllerSecurityPlannerBridge;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\PrincipalInterface as AuthorizationPrincipalInterface;
use Quantum\Authorization\Decision\Decision;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Controllers\Security\Budget\ControllerSecurityBudget;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Context\Principal as SecurityPrincipal;
use Quantum\Controllers\Security\Context\PrincipalType as SecurityPrincipalType;
use Quantum\Controllers\Security\Context\SecurityAttributes;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionCache;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\ControllerTarget;
use Quantum\Controllers\ControllerDefinition;
use VoltStack\Framework\Application;

final class ControllerSecurityPlannerBridgeTest extends TestCase
{
    public function test_bridge_returns_null_when_no_requirements_or_permissions_in_metadata(): void
    {
        $app = self::buildApp();
        $bridge = new ControllerSecurityPlannerBridge(
            authorization: $app->make(AuthorizationManagerInterface::class),
        );

        $request = self::buildSecurityRequest(metadata: []);

        self::assertNull($bridge->tryEvaluate($request));
    }

    public function test_bridge_returns_allow_when_gate_matches_via_planner_and_requirements_present(): void
    {
        $app = self::buildApp();

        $gates = $app->make(GateRegistry::class);
        $gates->define('post:view', static fn (AuthorizationPrincipalInterface $principal): bool => $principal->id() === 'user-1');

        $bridge = new ControllerSecurityPlannerBridge(
            authorization: $app->make(AuthorizationManagerInterface::class),
        );

        $request = self::buildSecurityRequest(
            principalId: 'user-1',
            metadata: [
                'authorization_ability' => 'post:view',
                'authorization_requirements' => [
                    ['ability' => 'post:view'],
                ],
            ],
        );

        $result = $bridge->tryEvaluate($request);

        self::assertNotNull($result);
        self::assertSame(SecurityDecisionEffect::Allow, $result->effect);
        self::assertSame('authorization.planner', $result->policyId);
        self::assertArrayHasKey('requirements', $result->obligations);
    }

    public function test_bridge_returns_deny_when_gate_misses_and_fail_closed(): void
    {
        $app = self::buildApp();

        $bridge = new ControllerSecurityPlannerBridge(
            authorization: $app->make(AuthorizationManagerInterface::class),
        );

        $request = self::buildSecurityRequest(
            principalId: 'user-1',
            metadata: [
                'authorization_requirements' => [
                    ['ability' => 'post:delete'],
                ],
            ],
        );

        $result = $bridge->tryEvaluate($request);

        self::assertNotNull($result);
        self::assertSame(SecurityDecisionEffect::Deny, $result->effect);
    }

    public function test_bridge_extracts_permissions_when_explicit_requirements_missing(): void
    {
        $app = self::buildApp();
        $gates = $app->make(GateRegistry::class);
        $gates->define('reports:export', static fn (AuthorizationPrincipalInterface $principal): bool => true);
        $bridge = new ControllerSecurityPlannerBridge(
            authorization: $app->make(AuthorizationManagerInterface::class),
        );

        $request = self::buildSecurityRequest(
            principalId: 'u1',
            resource: 'reports',
            action: 'export',
            metadata: [
                'permissions' => ['reports:export'],
            ],
        );

        $result = $bridge->tryEvaluate($request);

        self::assertNotNull($result);
        self::assertSame(SecurityDecisionEffect::Allow, $result->effect);
        self::assertSame('authorization.planner', $result->policyId);
    }

    public function test_bridge_propagates_fingerprint_in_obligations_when_available(): void
    {
        $managerMock = self::createMock(AuthorizationManagerInterface::class);
        $managerMock
            ->method('decide')
            ->willReturn(new DecisionResult(
                decision: Decision::Allow,
                reasonCode: 'fp_tested',
                metadata: ['stage' => 'manifest_requirements'],
                metadataFingerprint: 'fp_known_123',
            ));

        $bridge = new ControllerSecurityPlannerBridge($managerMock);

        $request = self::buildSecurityRequest(
            metadata: [
                'authorization_requirements' => [['ability' => 'any:thing']],
            ],
        );

        $decision = $bridge->tryEvaluate($request);

        self::assertInstanceOf(SecurityDecision::class, $decision);
        self::assertSame(SecurityDecisionEffect::Allow, $decision->effect);
        self::assertSame('fp_tested', $decision->reasonCode);
        self::assertSame('fp_known_123', $decision->obligations['authorization.metadata.fingerprint'] ?? null);
    }

    private static function buildApp(): Application
    {
        $basePath = sys_get_temp_dir();

        return new Application($basePath);
    }

    private static function buildSecurityRequest(
        string $principalId = 'anonymous',
        bool $authenticated = true,
        mixed $resource = null,
        string $action = 'view',
        array $metadata = [],
    ): SecurityEvaluationRequest {
        $principal = new SecurityPrincipal(
            id: $principalId,
            type: $authenticated ? SecurityPrincipalType::User : SecurityPrincipalType::Anonymous,
            authenticated: $authenticated,
            claims: [],
        );

        $security = new ControllerSecurityContext(
            principal: $principal,
            tenant: null,
            authenticationStrength: AuthenticationStrength::Password,
            attributes: new SecurityAttributes([]),
            decisions: new SecurityDecisionCache(1024),
            executionId: 'exec-bridge-' . uniqid('', true),
            budget: new ControllerSecurityBudget(512),
        );

        $target = ControllerTarget::fromDefinition(new ControllerDefinition('App\\Controllers\\BridgeDemo@' . $action));

        return new SecurityEvaluationRequest(
            security: $security,
            target: $target,
            action: $action,
            resource: $resource,
            metadata: $metadata,
        );
    }
}
