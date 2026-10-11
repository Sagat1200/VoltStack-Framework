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

final class AuthorizationAdaptiveMultiFactorTest extends TestCase
{
    public function test_step_up_challenge_when_required_factor_missing(): void
    {
        $stage = new AdaptiveAccessStage(enabled: true);

        $request = $this->buildRequest([
            'authorization.step_up.required_factors' => ['otp', 'biometric'],
            'authorization.step_up.completed_factors' => ['otp'],
        ]);

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame(Decision::Challenge, $results[0]->decision());
        self::assertSame('auth.step_up_required', $results[0]->reasonCode());
        self::assertSame('biometric', $results[0]->metadata()['next_factor'] ?? null);
        self::assertSame(['otp'], $results[0]->metadata()['completed_factors'] ?? null);
    }

    public function test_no_challenge_when_all_factors_completed(): void
    {
        $stage = new AdaptiveAccessStage(enabled: true);

        $request = $this->buildRequest([
            'authorization.step_up.required_factors' => ['otp', 'biometric'],
            'authorization.step_up.completed_factors' => ['otp', 'biometric'],
        ]);

        // Todos completados: no hay challenge multi-factor.
        // Como no hay risk_score, el stage retorna vacio.
        self::assertSame([], $stage->evaluate($request));
    }

    public function test_no_challenge_when_no_required_factors(): void
    {
        $stage = new AdaptiveAccessStage(enabled: true);

        $request = $this->buildRequest([]);

        self::assertSame([], $stage->evaluate($request));
    }

    public function test_next_factor_is_first_incomplete_in_order(): void
    {
        $stage = new AdaptiveAccessStage(enabled: true);

        $request = $this->buildRequest([
            'authorization.step_up.required_factors' => ['password', 'otp', 'biometric'],
            'authorization.step_up.completed_factors' => ['password'],
        ]);

        $results = $stage->evaluate($request);

        self::assertCount(1, $results);
        self::assertSame('otp', $results[0]->metadata()['next_factor'] ?? null);
    }

    public function test_multi_factor_takes_precedence_over_risk_score(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            denyThreshold: 10,
        );

        $request = $this->buildRequest([
            'auth_risk_score' => 90,
            'authorization.step_up.required_factors' => ['otp'],
            'authorization.step_up.completed_factors' => [],
        ]);

        $results = $stage->evaluate($request);

        // Aunque el risk_score excede el deny_threshold, el challenge
        // multi-factor tiene precedencia.
        self::assertCount(1, $results);
        self::assertSame(Decision::Challenge, $results[0]->decision());
        self::assertSame('otp', $results[0]->metadata()['next_factor'] ?? null);
    }

    private function buildRequest(array $attributes): AuthorizationRequest
    {
        $context = new AuthorizationContext('test-req', attributes: $attributes);

        return new AuthorizationRequest(
            new Ability('posts.view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'resource-1', 'string'),
            $context,
        );
    }
}
