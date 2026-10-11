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
use Quantum\Authorization\RateLimit\InMemoryRateLimitStore;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;

final class AuthorizationAdaptiveRateLimitTest extends TestCase
{
    public function test_rate_limit_denies_after_max_requests(): void
    {
        $store = new InMemoryRateLimitStore();
        $stage = new AdaptiveAccessStage(
            enabled: true,
            rateLimitStore: $store,
            rateLimitConfig: [
                'enabled' => true,
                'max_requests' => 2,
                'window_seconds' => 60,
            ],
        );

        $request = $this->buildRequest('u_1');

        // 1st y 2nd requests: no decision (rate limit not exceeded).
        self::assertSame([], $stage->evaluate($request));
        self::assertSame([], $stage->evaluate($request));

        // 3rd request: denegado por rate limit.
        $results = $stage->evaluate($request);
        self::assertCount(1, $results);
        self::assertSame(Decision::Deny, $results[0]->decision());
        self::assertSame('auth.rate_limit_exceeded', $results[0]->reasonCode());
        self::assertSame(3, $results[0]->metadata()['rate_limit_count'] ?? null);
        self::assertSame(2, $results[0]->metadata()['rate_limit_max'] ?? null);
    }

    public function test_rate_limit_disabled_returns_empty(): void
    {
        $store = new InMemoryRateLimitStore();
        $stage = new AdaptiveAccessStage(
            enabled: true,
            rateLimitStore: $store,
            rateLimitConfig: [
                'enabled' => false,
                'max_requests' => 1,
                'window_seconds' => 60,
            ],
        );

        $request = $this->buildRequest('u_1');

        // Incluso despues de muchas llamadas, no hay denegacion.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame([], $stage->evaluate($request));
        }
    }

    public function test_rate_limit_without_store_returns_empty(): void
    {
        $stage = new AdaptiveAccessStage(
            enabled: true,
            rateLimitStore: null,
            rateLimitConfig: [
                'enabled' => true,
                'max_requests' => 1,
                'window_seconds' => 60,
            ],
        );

        $request = $this->buildRequest('u_1');

        self::assertSame([], $stage->evaluate($request));
    }

    public function test_rate_limit_counter_is_per_principal(): void
    {
        $store = new InMemoryRateLimitStore();
        $stage = new AdaptiveAccessStage(
            enabled: true,
            rateLimitStore: $store,
            rateLimitConfig: [
                'enabled' => true,
                'max_requests' => 1,
                'window_seconds' => 60,
            ],
        );

        $requestA = $this->buildRequest('u_a');
        $requestB = $this->buildRequest('u_b');

        // u_a alcanza el limite.
        self::assertSame([], $stage->evaluate($requestA));
        self::assertCount(1, $stage->evaluate($requestA));

        // u_b no se ve afectado (contador independiente).
        self::assertSame([], $stage->evaluate($requestB));
    }

    public function test_rate_limit_reset_clears_counter(): void
    {
        $store = new InMemoryRateLimitStore();
        $stage = new AdaptiveAccessStage(
            enabled: true,
            rateLimitStore: $store,
            rateLimitConfig: [
                'enabled' => true,
                'max_requests' => 1,
                'window_seconds' => 60,
            ],
        );

        $request = $this->buildRequest('u_1');

        self::assertSame([], $stage->evaluate($request));
        self::assertCount(1, $stage->evaluate($request));

        $store->reset('rl:u_1');

        // Despues del reset, vuelve a permitir.
        self::assertSame([], $stage->evaluate($request));
    }

    private function buildRequest(string $principalId): AuthorizationRequest
    {
        $context = new AuthorizationContext('test-req', attributes: []);

        return new AuthorizationRequest(
            new Ability('posts.view'),
            new Principal($principalId),
            new SubjectDescriptor(SubjectType::Scalar, 'resource-1', 'string'),
            $context,
        );
    }
}
