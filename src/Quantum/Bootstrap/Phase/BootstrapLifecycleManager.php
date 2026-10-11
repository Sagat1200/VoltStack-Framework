<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Phase;

use LogicException;
use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\BootstrapResult;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use Throwable;

/**
 * Orquesta el ciclo de vida completo del bootstrap usando BootstrapStateMachine.
 *
 * Estados cubiertos:
 *   New -> Discovering -> Registering -> Configuring -> Compiling -> Booting
 *       -> Warming -> Ready -> Draining -> Stopping -> Stopped
 *   Cualquier fase puede transicionar a Failed.
 *
 * El manager es la fuente de verdad del estado del ciclo de vida. El bootstrapper
 * ejecuta las fases internamente; el manager sincroniza su estado con el resultado
 * del bootstrapper y controla las fases de drain/shutdown que el bootstrapper no
 * orquesta.
 *
 * Reglas de gates:
 *   - boot() sólo puede invocarse desde New.
 *   - drain() sólo puede invocarse desde Ready.
 *   - shutdown() puede invocarse desde Draining o Stopping.
 *   - markFailed() puede invocarse desde cualquier estado excepto Stopped.
 */
final class BootstrapLifecycleManager
{
    private BootstrapStateMachine $stateMachine;

    private ?BootstrapResult $result = null;

    /**
     * Reporte del resultado de la operación de drain, si la hubo.
     *
     * Se mantiene tipado como `object` para no acoplar la capa de bootstrap
     * (`Quantum\Bootstrap\Phase`) a la capa de runtime evidence
     * (`VoltStack\Runtime\Evidence\RuntimeDrainReport`). La capa de runtime
     * puede pasar un `RuntimeDrainReport` (o cualquier objeto de reporte)
     * devolviéndolo desde `$drainOperation` y recuperarlo luego vía
     * `drainReport()`.
     */
    private ?object $drainReport = null;

    /** @var list<BootstrapState> */
    private array $happyPath = [
        BootstrapState::New,
        BootstrapState::Discovering,
        BootstrapState::Registering,
        BootstrapState::Configuring,
        BootstrapState::Compiling,
        BootstrapState::Booting,
        BootstrapState::Warming,
        BootstrapState::Ready,
    ];

    public function __construct(?BootstrapStateMachine $stateMachine = null)
    {
        $this->stateMachine = $stateMachine ?? new BootstrapStateMachine();
    }

    public function currentState(): BootstrapState
    {
        return $this->stateMachine->current();
    }

    public function isReady(): bool
    {
        return $this->stateMachine->current() === BootstrapState::Ready;
    }

    public function isFailed(): bool
    {
        return $this->stateMachine->current() === BootstrapState::Failed;
    }

    public function isStopped(): bool
    {
        return $this->stateMachine->current() === BootstrapState::Stopped;
    }

    public function canBoot(): bool
    {
        return $this->stateMachine->canTransitionTo(BootstrapState::Discovering);
    }

    public function canDrain(): bool
    {
        return $this->stateMachine->canTransitionTo(BootstrapState::Draining);
    }

    public function canShutdown(): bool
    {
        return $this->stateMachine->canTransitionTo(BootstrapState::Stopping)
            || $this->stateMachine->canTransitionTo(BootstrapState::Stopped);
    }

    public function result(): ?BootstrapResult
    {
        return $this->result;
    }

    /**
     * Devuelve el reporte de drain almacenado (si la operación de drenaje
     * devolvió uno). Típicamente un `VoltStack\Runtime\Evidence\RuntimeDrainReport`.
     */
    public function drainReport(): ?object
    {
        return $this->drainReport;
    }

    /**
     * Transiciona la máquina de estados interna al estado objetivo.
     *
     * Usado por el Bootstrapper durante las fases de boot para que el manager
     * sea la fuente de verdad del estado en todo momento.
     *
     * @throws LogicException si la transición no es válida.
     */
    public function transitionTo(BootstrapState $next): void
    {
        $this->stateMachine->transitionTo($next);
    }

    /**
     * Devuelve true si la transición al estado objetivo es válida desde el estado actual.
     */
    public function canTransitionTo(BootstrapState $next): bool
    {
        return $this->stateMachine->canTransitionTo($next);
    }

    /**
     * Ejecuta el ciclo de boot a través del bootstrapper.
     *
     * En caso de éxito sincroniza el estado interno con el estado final del
     * resultado (habitualmente Ready). En caso de error transiciona a Failed
     * antes de relanzar la excepción.
     *
     * @throws LogicException si el estado actual no permite boot.
     * @throws Throwable si el bootstrapper lanza una excepción.
     */
    public function boot(
        BootstrapperInterface $bootstrapper,
        ApplicationPlan $plan,
        ?BootstrapContext $context = null,
    ): BootstrapResult {
        if (! $this->canBoot()) {
            throw new LogicException(sprintf(
                'Cannot bootstrap from current state [%s]; boot is only allowed from [NEW].',
                $this->stateMachine->current()->value,
            ));
        }

        // Inyecta este manager como fuente de verdad del estado dentro del
        // bootstrapper concreto, de forma que las transiciones de fases ocurran
        // sobre la misma máquina de estados que el manager orquesta.
        if ($bootstrapper instanceof Bootstrapper) {
            $bootstrapper->setLifecycleManager($this);
        }

        try {
            $result = $bootstrapper->bootPlan($plan, $context);
            $this->result = $result;
            $this->syncToState($result->state());

            return $result;
        } catch (Throwable $throwable) {
            $this->markFailed();

            throw $throwable;
        }
    }

    /**
     * Orquesta el drenaje y apagado: Ready -> Draining -> Stopping -> Stopped.
     *
     * @param callable():mixed|null $drainOperation operación de drenaje opcional
     *        (ej. terminar workers, cerrar conexiones). Se ejecuta en estado Draining.
     *        Si devuelve un objeto, se almacena como reporte de drain y es
     *        recuperable vía `drainReport()` (típicamente un `RuntimeDrainReport`).
     * @param callable():void|null $shutdownOperation operación de apagado opcional.
     *        Se ejecuta en estado Stopping.
     *
     * @throws LogicException si el estado actual no permite drain.
     */
    public function drain(
        ?callable $drainOperation = null,
        ?callable $shutdownOperation = null,
    ): BootstrapState {
        if (! $this->canDrain()) {
            throw new LogicException(sprintf(
                'Cannot drain from current state [%s]; drain is only allowed from [READY].',
                $this->stateMachine->current()->value,
            ));
        }

        $this->stateMachine->transitionTo(BootstrapState::Draining);

        if ($drainOperation !== null) {
            $drainResult = $drainOperation();

            if (is_object($drainResult)) {
                $this->drainReport = $drainResult;
            }
        }

        $this->stateMachine->transitionTo(BootstrapState::Stopping);

        if ($shutdownOperation !== null) {
            $shutdownOperation();
        }

        $this->stateMachine->transitionTo(BootstrapState::Stopped);

        return $this->stateMachine->current();
    }

    /**
     * Finaliza el apagado desde los estados Draining o Stopping.
     *
     * @throws LogicException si el estado actual no permite shutdown.
     */
    public function shutdown(): BootstrapState
    {
        $current = $this->stateMachine->current();

        if ($current === BootstrapState::Draining) {
            $this->stateMachine->transitionTo(BootstrapState::Stopping);
        }

        if (! $this->stateMachine->canTransitionTo(BootstrapState::Stopped)) {
            throw new LogicException(sprintf(
                'Cannot shutdown from current state [%s].',
                $this->stateMachine->current()->value,
            ));
        }

        $this->stateMachine->transitionTo(BootstrapState::Stopped);

        return $this->stateMachine->current();
    }

    /**
     * Marca el ciclo de vida como fallido si la transición es válida.
     */
    public function markFailed(): void
    {
        if ($this->stateMachine->canTransitionTo(BootstrapState::Failed)) {
            $this->stateMachine->transitionTo(BootstrapState::Failed);
        }
    }

    /**
     * Avanza la máquina de estados a través del happy path hasta alcanzar el
     * estado objetivo. Se usa para sincronizar el estado del manager con el
     * resultado del bootstrapper tras un boot exitoso.
     */
    private function syncToState(BootstrapState $target): void
    {
        $targetIndex = array_search($target, $this->happyPath, true);

        if ($targetIndex === false) {
            return;
        }

        foreach ($this->happyPath as $state) {
            if ($this->stateMachine->current() === $state) {
                if ($state === $target) {
                    break;
                }

                continue;
            }

            if ($this->stateMachine->canTransitionTo($state)) {
                $this->stateMachine->transitionTo($state);
            }

            if ($state === $target) {
                break;
            }
        }
    }
}
