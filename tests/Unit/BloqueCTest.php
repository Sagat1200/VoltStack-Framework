<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Risk\CompositeRiskSignalEngine;
use Quantum\Auth\Risk\ImpossibleTravelVelocitySignal;
use Quantum\Auth\Risk\IpDriftSignal;
use Quantum\Auth\Risk\IrregularTimeSignal;
use Quantum\Auth\Risk\NewDeviceSignal;
use Quantum\Auth\Risk\RiskScore;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;

final class BloqueCTest extends TestCase
{
    private function buildIdentityWithAttrs(array $attrs): GenericIdentity
    {
        return new GenericIdentity(
            identifier: new IdentityIdentifier('risk_usr_' . bin2hex(random_bytes(4))),
            type: 'user',
            attributes: $attrs,
        );
    }

    private function buildContext(GenericIdentity $identity, array $ctxAttrs = []): AuthenticationContext
    {
        return new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            requestId: 'req-risk-' . bin2hex(random_bytes(4)),
            method: 'pwd',
            attributes: $ctxAttrs,
        );
    }

    public function test_risk_score_zero_is_level_low_on_clean_signals(): void
    {
        $engine = new CompositeRiskSignalEngine([new NewDeviceSignal(), new IpDriftSignal(), new IrregularTimeSignal()]);
        $identity = $this->buildIdentityWithAttrs([
            'known_device_refs' => ['dev_known_01'],
            'last_known_ip_prefix' => '10.0.0',
        ]);
        $context = $this->buildContext($identity);
        $request = new AuthenticationRequest(
            requestId: 'risk-clean-' . bin2hex(random_bytes(4)),
            attributes: [
                'device_ref' => 'dev_known_01',
                'ip_prefix' => '10.0.0',
                'tz_hint' => '1',
            ],
        );

        $risk = $engine->evaluate($request, $context);
        self::assertSame(0, $risk->score);
        self::assertSame(RiskScore::LEVEL_LOW, $risk->level);
        self::assertSame([], $risk->reasonCodes);
    }

    public function test_new_device_signal_adds_25_risk_points(): void
    {
        $signal = new NewDeviceSignal();
        $identity = $this->buildIdentityWithAttrs([
            'known_device_refs' => ['dev_trusted_abc', 'dev_trusted_xyz'],
        ]);
        $context = $this->buildContext($identity);
        $request = new AuthenticationRequest(
            requestId: 'risk-newdev-' . bin2hex(random_bytes(4)),
            attributes: [
                'device_ref' => 'dev_unseen_007',
            ],
        );

        $risk = $signal->evaluate($request, $context);
        self::assertSame(25, $risk->score);
        self::assertContains('new_device_detected', $risk->reasonCodes);
    }

    public function test_ip_drift_signal_adds_15_risk_points(): void
    {
        $signal = new IpDriftSignal();
        $identity = $this->buildIdentityWithAttrs([
            'last_known_ip_prefix' => '192.168.1',
        ]);
        $context = $this->buildContext($identity);
        $request = new AuthenticationRequest(
            requestId: 'risk-ipdrift-' . bin2hex(random_bytes(4)),
            attributes: [
                'ip_prefix' => '203.0.113',
            ],
        );

        $risk = $signal->evaluate($request, $context);
        self::assertSame(15, $risk->score);
        self::assertContains('ip_prefix_drift', $risk->reasonCodes);
    }

    public function test_irregular_time_window_between_02_05_adds_10_points(): void
    {
        $signal = new IrregularTimeSignal();

        $request = new AuthenticationRequest(
            requestId: 'risk-time-' . bin2hex(random_bytes(4)),
            attributes: ['tz_hint' => '3'],
        );

        $risk = $signal->evaluate($request);
        $localHour = $risk->metadata['local_hour'] ?? null;
        if ($localHour >= 2 && $localHour <= 5) {
            self::assertSame(10, $risk->score);
            self::assertContains('irregular_auth_time_window', $risk->reasonCodes);
        } else {
            self::assertSame(0, $risk->score);
            self::assertNotContains('irregular_auth_time_window', $risk->reasonCodes);
        }
    }

    public function test_impossible_travel_velocity_triggers_40_points(): void
    {
        $signal = new ImpossibleTravelVelocitySignal();
        $now = time();
        $identity = $this->buildIdentityWithAttrs([
            'last_auth_at' => $now - 3600,
            'last_tz_hint' => '-5',
        ]);
        $context = $this->buildContext($identity);
        $request = new AuthenticationRequest(
            requestId: 'risk-travel-' . bin2hex(random_bytes(4)),
            attributes: ['tz_hint' => '3'],
        );

        $risk = $signal->evaluate($request, $context);
        self::assertSame(40, $risk->score);
        self::assertContains('impossible_travel_velocity', $risk->reasonCodes);
    }

    public function test_composite_engine_sums_scores_and_returns_medium_at_80_below_critical(): void
    {
        $engine = new CompositeRiskSignalEngine([
            new NewDeviceSignal(),
            new IpDriftSignal(),
            new IrregularTimeSignal(),
        ]);

        $identity = $this->buildIdentityWithAttrs([
            'known_device_refs' => ['dev_good'],
            'last_known_ip_prefix' => '10.0.0',
        ]);
        $context = $this->buildContext($identity);
        $request = new AuthenticationRequest(
            requestId: 'risk-combo-' . bin2hex(random_bytes(4)),
            attributes: [
                'device_ref' => 'dev_new_evil',
                'ip_prefix' => '198.51.100',
                // Fuerza ventana irregular (02:00-05:59 local) de forma determinística.
                'tz_hint' => 3 - (int) gmdate('G'),
            ],
        );

        $risk = $engine->evaluate($request, $context);
        self::assertSame(50, $risk->score);
        self::assertSame(RiskScore::LEVEL_MEDIUM, $risk->level);
        self::assertContains('new_device_detected', $risk->reasonCodes);
        self::assertContains('ip_prefix_drift', $risk->reasonCodes);
        self::assertContains('irregular_auth_time_window', $risk->reasonCodes);
    }

    public function test_composite_engine_caps_score_at_100_without_overflow(): void
    {
        $manyProviders = [];
        for ($i = 0; $i < 10; $i++) {
            $manyProviders[] = new class implements \Quantum\Auth\Contracts\RiskSignalProviderInterface {
                public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore
                {
                    return RiskScore::fromScore(30, ['fake_' . bin2hex(random_bytes(2))]);
                }
            };
        }
        $engine = new CompositeRiskSignalEngine($manyProviders);

        $request = new AuthenticationRequest(requestId: 'cap-test');
        $risk = $engine->evaluate($request);

        self::assertSame(100, $risk->score);
        self::assertSame(RiskScore::LEVEL_CRITICAL, $risk->level);
    }

    public function test_orchestrator_without_risk_engine_injected_has_no_risk_metadata_on_authenticated_decision(): void
    {
        $identity = $this->buildIdentityWithAttrs([]);
        $ctx = $this->buildContext($identity);

        $allowAuthenticator = new class($ctx) implements AuthenticatorInterface {
            public function __construct(private readonly AuthenticationContext $contextToReturn)
            {
            }

            public function supports(AuthenticationOperationContext $context): bool
            {
                return true;
            }

            public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
            {
                return AuthenticationDecision::authenticated(
                    $this->contextToReturn,
                    ['authenticator' => 'risk_baseline_auth'],
                );
            }
        };

        $resolver = $this->createMock(AuthenticatorResolverInterface::class);
        $resolver->method('resolve')->willReturn([$allowAuthenticator]);

        $orchestrator = new AuthenticationOrchestrator($resolver);

        $request = new AuthenticationRequest(
            requestId: 'risk-noop-' . bin2hex(random_bytes(4)),
            transport: 'runtime',
            attributes: [
                'credentials' => ['identifier' => 'riskclean@test', 'password' => 'Valid-123!'],
                'device_ref' => 'dev_x',
                'ip_prefix' => '10.0.0',
            ],
        );
        $opCtx = new AuthenticationOperationContext('authenticate', $request);
        $decision = $orchestrator->execute($opCtx);

        self::assertTrue($decision->isAuthenticated());
        self::assertArrayNotHasKey('risk_score', $decision->metadata);
        self::assertArrayNotHasKey('risk_level', $decision->metadata);
        self::assertArrayNotHasKey('risk_reason_codes', $decision->metadata);
    }
}
