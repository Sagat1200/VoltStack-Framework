<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\Stages\AdaptiveAccessStage;
use Quantum\Authorization\Decision\Decision;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationAdaptiveDimensionsTest extends TestCase
{
    public function test_operation_policy_overrides_global_deny_threshold(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            denyThreshold: 80,
            policies: [
                'operation:posts.publish' => [
                    'deny_threshold' => 30,
                ],
            ],
        );

        $request = $this->buildRequest(
            ability: 'posts.publish',
            attributes: ['auth_risk_score' => 40],
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Deny, $results[0]->decision());
        self::assertSame('operation:posts.publish', $results[0]->metadata()['adaptive_policy_dimension'] ?? null);
    }

    public function test_global_policy_applied_when_no_dimension_matches(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            denyThreshold: 80,
            policies: [
                'operation:posts.delete' => [
                    'deny_threshold' => 20,
                ],
            ],
        );

        $request = $this->buildRequest(
            ability: 'posts.publish',
            attributes: ['auth_risk_score' => 90],
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Deny, $results[0]->decision());
        self::assertSame('global', $results[0]->metadata()['adaptive_policy_dimension'] ?? null);
    }

    public function test_canal_policy_takes_precedence_over_tenant(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            denyThreshold: 80,
            policies: [
                'canal:mobile' => [
                    'deny_threshold' => 25,
                ],
                'tenant:acme' => [
                    'deny_threshold' => 60,
                ],
            ],
        );

        $request = $this->buildRequest(
            ability: 'posts.view',
            attributes: [
                'auth_risk_score' => 40,
                'authorization.canal' => 'mobile',
                'authorization.tenant' => 'acme',
            ],
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Deny, $results[0]->decision());
        self::assertSame('canal:mobile', $results[0]->metadata()['adaptive_policy_dimension'] ?? null);
    }

    public function test_allow_threshold_shortcuts_to_allow(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            allowThreshold: 10,
            denyThreshold: 80,
        );

        $request = $this->buildRequest(
            ability: 'posts.view',
            attributes: ['auth_risk_score' => 5],
        );

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Allow, $results[0]->decision());
        self::assertSame('allow', $results[0]->metadata()['adaptive_access_action'] ?? null);
        self::assertSame(10, $results[0]->metadata()['risk_allow_threshold'] ?? null);
    }

    public function test_missing_risk_score_returns_empty(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            denyThreshold: 80,
        );

        $request = $this->buildRequest(
            ability: 'posts.view',
            attributes: [],
        );

        self::assertSame([], $stage->evaluate($request));
    }

    public function test_disabled_stage_returns_empty(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: false,
            denyThreshold: 0,
        );

        $request = $this->buildRequest(
            ability: 'posts.view',
            attributes: ['auth_risk_score' => 100],
        );

        self::assertSame([], $stage->evaluate($request));
    }

    private function buildRequest(string $ability, array $attributes = []): AuthorizationRequest
    {
        $context = new AuthorizationContext('test-req', attributes: $attributes);

        return new AuthorizationRequest(
            new Ability($ability),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'resource-1', 'string'),
            $context,
        );
    }
}
