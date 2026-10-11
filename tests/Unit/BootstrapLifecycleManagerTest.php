<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\BootstrapResult;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use Quantum\Bootstrap\Phase\BootstrapLifecycleManager;
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Bootstrap\Phase\BootstrapStateMachine;
use RuntimeException;
use VoltStack\Framework\Application;

final class BootstrapLifecycleManagerTest extends TestCase
{
    private function makePlan(): ApplicationPlan
    {
        return ApplicationBuilder::create(sys_get_temp_dir())->build();
    }

    private function makeApp(): Application
    {
        return new Application(sys_get_temp_dir());
    }

    public function test_initial_state_is_new_and_gates_refuse_drain_until_ready(): void
    {
        $manager = new BootstrapLifecycleManager();

        self::assertSame(BootstrapState::New, $manager->currentState());
        self::assertTrue($manager->canBoot());
        self::assertFalse($manager->canDrain());
        self::assertFalse($manager->canShutdown());
        self::assertFalse($manager->isReady());
        self::assertFalse($manager->isFailed());
        self::assertFalse($manager->isStopped());
        self::assertNull($manager->result());
    }

    public function test_boot_success_syncs_state_to_ready_and_stores_result(): void
    {
        $manager = new BootstrapLifecycleManager();
        $plan = $this->makePlan();
        $context = BootstrapContext::forConsole(environment: 'testing', executionMode: 'single', profile: 'worker');

        $result = new BootstrapResult(
            app: $this->makeApp(),
            plan: $plan,
            context: $context,
            state: BootstrapState::Ready,
            warmed: true,
            ready: true,
        );

        $bootstrapper = $this->createMock(BootstrapperInterface::class);
        $bootstrapper->expects(self::once())
            ->method('bootPlan')
            ->with($plan, $context)
            ->willReturn($result);

        $returned = $manager->boot($bootstrapper, $plan, $context);

        self::assertSame($result, $returned);
        self::assertSame($result, $manager->result());
        self::assertSame(BootstrapState::Ready, $manager->currentState());
        self::assertTrue($manager->isReady());
        self::assertTrue($manager->canDrain());
        self::assertFalse($manager->canBoot());
    }

    public function test_boot_refuses_when_not_in_new_state(): void
    {
        $stateMachine = new BootstrapStateMachine();
        $stateMachine->transitionTo(BootstrapState::Discovering);
        $manager = new BootstrapLifecycleManager($stateMachine);

        $bootstrapper = $this->createMock(BootstrapperInterface::class);
        $bootstrapper->expects(self::never())->method('bootPlan');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot bootstrap from current state [DISCOVERING]');

        $manager->boot($bootstrapper, $this->makePlan());
    }

    public function test_boot_failure_transitions_to_failed_and_rethrows(): void
    {
        $manager = new BootstrapLifecycleManager();
        $plan = $this->makePlan();

        $bootstrapper = $this->createMock(BootstrapperInterface::class);
        $bootstrapper->expects(self::once())
            ->method('bootPlan')
            ->willThrowException(new RuntimeException('boot exploded'));

        try {
            $manager->boot($bootstrapper, $plan);
            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            self::assertSame('boot exploded', $e->getMessage());
        }

        self::assertTrue($manager->isFailed());
        self::assertSame(BootstrapState::Failed, $manager->currentState());
        self::assertNull($manager->result());
        self::assertFalse($manager->canBoot());
        self::assertFalse($manager->canDrain());
    }

    public function test_drain_runs_operations_and_reaches_stopped(): void
    {
        $manager = new BootstrapLifecycleManager(new BootstrapStateMachine(BootstrapState::Ready));

        $drainRan = false;
        $shutdownRan = false;
        $drainState = null;
        $shutdownState = null;

        $final = $manager->drain(
            drainOperation: function () use ($manager, &$drainRan, &$drainState): void {
                $drainRan = true;
                $drainState = $manager->currentState();
            },
            shutdownOperation: function () use ($manager, &$shutdownRan, &$shutdownState): void {
                $shutdownRan = true;
                $shutdownState = $manager->currentState();
            },
        );

        self::assertTrue($drainRan);
        self::assertTrue($shutdownRan);
        self::assertSame(BootstrapState::Draining, $drainState);
        self::assertSame(BootstrapState::Stopping, $shutdownState);
        self::assertSame(BootstrapState::Stopped, $final);
        self::assertSame(BootstrapState::Stopped, $manager->currentState());
        self::assertTrue($manager->isStopped());
        self::assertFalse($manager->canDrain());
        self::assertFalse($manager->canShutdown());
    }

    public function test_drain_refuses_when_not_ready(): void
    {
        $manager = new BootstrapLifecycleManager();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot drain from current state [NEW]');

        $manager->drain();
    }

    public function test_shutdown_from_draining_reaches_stopped(): void
    {
        $stateMachine = new BootstrapStateMachine(BootstrapState::Ready);
        $stateMachine->transitionTo(BootstrapState::Draining);
        $manager = new BootstrapLifecycleManager($stateMachine);

        self::assertTrue($manager->canShutdown());

        $final = $manager->shutdown();

        self::assertSame(BootstrapState::Stopped, $final);
        self::assertTrue($manager->isStopped());
    }

    public function test_shutdown_from_stopping_reaches_stopped(): void
    {
        $stateMachine = new BootstrapStateMachine(BootstrapState::Ready);
        $stateMachine->transitionTo(BootstrapState::Draining);
        $stateMachine->transitionTo(BootstrapState::Stopping);
        $manager = new BootstrapLifecycleManager($stateMachine);

        $final = $manager->shutdown();

        self::assertSame(BootstrapState::Stopped, $final);
    }

    public function test_shutdown_refuses_from_invalid_state(): void
    {
        $manager = new BootstrapLifecycleManager();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot shutdown from current state [NEW]');

        $manager->shutdown();
    }

    public function test_mark_failed_transitions_when_allowed(): void
    {
        $manager = new BootstrapLifecycleManager();

        $manager->markFailed();

        self::assertTrue($manager->isFailed());
        self::assertSame(BootstrapState::Failed, $manager->currentState());
    }

    public function test_mark_failed_is_noop_from_stopped(): void
    {
        $stateMachine = new BootstrapStateMachine(BootstrapState::Ready);
        $stateMachine->transitionTo(BootstrapState::Draining);
        $stateMachine->transitionTo(BootstrapState::Stopping);
        $stateMachine->transitionTo(BootstrapState::Stopped);
        $manager = new BootstrapLifecycleManager($stateMachine);

        $manager->markFailed();

        self::assertSame(BootstrapState::Stopped, $manager->currentState());
        self::assertFalse($manager->isFailed());
    }

    public function test_full_lifecycle_happy_path(): void
    {
        $manager = new BootstrapLifecycleManager();
        $plan = $this->makePlan();

        $result = new BootstrapResult(
            app: $this->makeApp(),
            plan: $plan,
            context: null,
            state: BootstrapState::Ready,
            warmed: true,
            ready: true,
        );

        $bootstrapper = $this->createStub(BootstrapperInterface::class);
        $bootstrapper->method('bootPlan')->willReturn($result);

        $manager->boot($bootstrapper, $plan);
        self::assertSame(BootstrapState::Ready, $manager->currentState());

        $manager->drain();
        self::assertSame(BootstrapState::Stopped, $manager->currentState());
    }
}
