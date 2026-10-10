<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\Container;
use Quantum\Container\Exceptions\BindingResolutionException;
use Quantum\Container\ScopeKind;

final class ContainerExecutionStateIsolationTest extends TestCase
{
    public function test_scoped_instances_are_isolated_per_concurrent_execution_slot(): void
    {
        $container = new Container();
        $sequence = 0;
        $container->scoped('scoped.service', static function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        });

        $slotA = $container->activateExecutionState();
        $scopeA = $container->enterRequestScope();
        $instanceA = $container->make('scoped.service');

        $slotB = $container->activateExecutionState();
        $scopeB = $container->enterRequestScope();
        $instanceB = $container->make('scoped.service');

        self::assertNotSame($instanceA, $instanceB);
        self::assertSame(1, $instanceA->id);
        self::assertSame(2, $instanceB->id);
        self::assertNotSame($scopeA, $scopeB);

        // Vuelvo a A, el scope frame de A sigue estando activo y conserva la instancia
        $container->deactivateExecutionState($slotB);
        $reenteredA = $container->make('scoped.service');
        self::assertSame($instanceA, $reenteredA);
        self::assertSame(1, $reenteredA->id);
        self::assertSame(2, $sequence);

        $container->deactivateExecutionState($slotA);
    }

    public function test_root_scope_is_shared_across_execution_slots_for_worker_owned_bindings(): void
    {
        $container = new Container();
        $sequence = 0;
        $container->scopedFor('worker.counter', static function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        }, ScopeKind::Worker);

        $slotA = $container->activateExecutionState();
        $workerA = $container->make('worker.counter');

        $slotB = $container->activateExecutionState();
        $workerB = $container->make('worker.counter');

        // Worker-owned va al root frame y es compartido por usar el mismo sharedRootScopeFrame
        self::assertSame($workerA, $workerB);
        self::assertSame(1, $workerA->id);
        self::assertSame(1, $sequence);

        $container->deactivateExecutionState($slotB);
        $container->deactivateExecutionState($slotA);
    }

    public function test_binding_resolution_stack_is_independent_per_execution_slot(): void
    {
        $container = new Container();
        $outerTraces = [];
        $innerTraces = [];

        $container->bind('inner.service', static function () use ($container, &$innerTraces): object {
            // Captura el stack de resolución actual cuando se está resolviendo `inner.service`
            $state = self::extractExecutionStateFromContainer($container);
            $innerTraces[] = $state?->bindingResolutionStack();

            return (object) ['kind' => 'inner'];
        });

        $container->bind('outer.service', static function () use ($container, &$outerTraces): object {
            $state = self::extractExecutionStateFromContainer($container);
            $outerTraces[] = [
                'before_inner' => $state?->bindingResolutionStack(),
                'inner' => $container->make('inner.service'),
                'after_inner' => $state?->bindingResolutionStack(),
            ];

            return (object) ['kind' => 'outer'];
        });

        $slotA = $container->activateExecutionState();
        $slotB = $container->activateExecutionState();

        // Resuelvo outer en B (ahora B es el stack activo)
        $outerB = $container->make('outer.service');
        // Vuelvo a A y resuelvo outer en A
        $container->deactivateExecutionState($slotB);
        $outerA = $container->make('outer.service');

        self::assertNotNull($outerA);
        self::assertNotNull($outerB);
        // Por slot, el stack de resolución de B no contamina A y viceversa.
        // La primera invocación de innerTraces corresponde a B, la segunda a A.
        self::assertCount(2, $innerTraces);
        // Dentro de inner, el stack de resolución contiene al menos a inner.service y a outer.service
        foreach ($innerTraces as $stack) {
            self::assertNotNull($stack);
            $abstracts = array_map(
                static fn (\Quantum\Container\Binding $binding): string => $binding->abstract,
                $stack,
            );
            self::assertContains('inner.service', $abstracts);
            self::assertContains('outer.service', $abstracts);
        }

        $container->deactivateExecutionState($slotA);
    }

    public function test_scoped_for_request_requires_request_scope_independently_per_slot(): void
    {
        $container = new Container();
        $container->scopedFor('req.bound', static fn (): object => (object) ['ok' => true], ScopeKind::Request);

        $slotA = $container->activateExecutionState();
        $container->enterRequestScope();
        $a = $container->make('req.bound');

        $slotB = $container->activateExecutionState();
        // Sin request scope en B, debe fallar aunque A lo tuviera activo
        try {
            $container->make('req.bound');
            self::fail('Expected BindingResolutionException in slot B without request scope');
        } catch (BindingResolutionException $exception) {
            self::assertStringContainsString('req.bound', $exception->getMessage());
            self::assertStringContainsString('request', $exception->getMessage());
        }

        $container->deactivateExecutionState($slotB);
        // Al volver a A, req.bound sigue resuelto en su scope frame
        $aAgain = $container->make('req.bound');
        self::assertSame($a, $aAgain);
        $container->deactivateExecutionState($slotA);
    }

    public function test_enter_leave_scope_does_not_affect_other_execution_slots(): void
    {
        $container = new Container();

        $slotA = $container->activateExecutionState();
        $scopeIdA = $container->enterScope('request');
        self::assertTrue($container->hasActiveScope());
        self::assertSame('request', $container->currentScopeKind());

        $slotB = $container->activateExecutionState();
        // En B, aun no entre a un scope => no hay scope activo mas alla del root frame
        self::assertFalse($container->hasActiveScope());
        $scopeIdB = $container->enterScope('job');
        self::assertSame('job', $container->currentScopeKind());

        // Vuelvo a A y conserva su request scope con el mismo id de scope
        $container->deactivateExecutionState($slotB);
        self::assertTrue($container->hasActiveScope());
        self::assertSame('request', $container->currentScopeKind());
        self::assertSame($scopeIdA, $container->currentScopeId());

        // En B el id de scope de job no era el mismo que el de request en A
        self::assertNotSame($scopeIdA, $scopeIdB);

        $container->deactivateExecutionState($slotA);
    }

    public function test_flush_scope_in_one_slot_does_not_flush_scoped_instances_in_other_slot(): void
    {
        $container = new Container();
        $sequence = 0;
        $container->scoped('counter', static function () use (&$sequence): object {
            return (object) ['id' => ++$sequence];
        });

        $slotA = $container->activateExecutionState();
        $container->enterRequestScope();
        $a = $container->make('counter');

        $slotB = $container->activateExecutionState();
        $container->enterRequestScope();
        $b = $container->make('counter');
        self::assertNotSame($a, $b);
        $container->flushScope();
        $bFlushed = $container->make('counter');
        self::assertNotSame($b, $bFlushed);

        $container->deactivateExecutionState($slotB);
        // flush en B no afectó a A
        $aAgain = $container->make('counter');
        self::assertSame($a, $aAgain);

        $container->deactivateExecutionState($slotA);
    }

    private static function extractExecutionStateFromContainer(Container $container): ?\Quantum\Container\ContainerExecutionState
    {
        // Como ContainerExecutionState es @internal y `currentExecutionState` es protected,
        // usamos reflexión para validarlo en tests sin abrir la API pública.
        $reflection = new \ReflectionClass($container);
        $method = $reflection->getMethod('currentExecutionState');
        $method->setAccessible(true);

        try {
            return $method->invoke($container);
        } finally {
            $method->setAccessible(false);
        }
    }
}
