<?php

declare(strict_types=1);

namespace Quantum\Container;

use InvalidArgumentException;

/**
 * Handle de un worker scope.
 *
 * Desde un worker se pueden abrir scopes de unidad (Request, Job, Command).
 * Tenant se abre desde una unidad (no directamente desde el worker).
 *
 * `WorkerHandle::close()` retorna un `CleanupReport` y marca el worker como
 * poisoned si el cleanup no fue limpio. El adaptador que abra el worker DEBE
 * inspeccionar `isPoisoned()` y marcar la salud del runtime.
 *
 * @internal
 */
final class WorkerHandle
{
    private bool $poisoned = false;
    private bool $closed = false;

    /** @var list<ScopeHandle> */
    private array $children = [];

    /**
     * @param null|callable(CleanupReport): void $onChildClose Callback opcional aplicado a cada ScopeHandle hijo creado por `enter()`.
     * @param null|callable(ScopeHandle): void $onChildEnter Callback opcional invocado tras abrir cada scope hijo con `enter()`.
     */
    public function __construct(
        private readonly ScopeStack $stack,
        private readonly ScopeHandle $worker,
        private readonly mixed $onChildClose = null,
        private readonly mixed $onChildEnter = null,
    ) {
    }

    public function token(): ScopeToken
    {
        return $this->worker->token();
    }

    public function isActive(): bool
    {
        return $this->worker->isActive();
    }

    public function seed(string $id, mixed $value): void
    {
        $this->worker->seed($id, $value);
    }

    /**
     * Abre un scope de unidad dentro del worker.
     *
     * Solo acepta Request, Job o Command. Para Tenant usar
     * `ScopeHandle::enterTenant()` desde una unidad ya abierta.
     *
     * @param array<string, mixed> $seeds
     */
    public function enter(ScopeKind $kind, array $seeds = []): ScopeHandle
    {
        if (!in_array($kind, [ScopeKind::Request, ScopeKind::Job, ScopeKind::Command], true)) {
            throw new InvalidArgumentException(
                'WorkerHandle::enter() only accepts Request, Job or Command scopes; use a unit handle to enter Tenant.',
            );
        }

        $frame = $this->stack->enterWithKind($kind);
        $handle = new ScopeHandle($this->stack, $frame, $this->onChildClose);
        $this->children[] = $handle;

        foreach ($seeds as $id => $value) {
            $handle->seed((string) $id, $value);
        }

        if ($this->onChildEnter !== null) {
            ($this->onChildEnter)($handle);
        }

        return $handle;
    }

    public function close(): CleanupReport
    {
        if ($this->closed) {
            return $this->worker->close();
        }

        $this->closed = true;

        $childErrors = [];
        foreach ($this->children as $child) {
            $childReport = $child->close();
            if (!$childReport->ok()) {
                $this->poisoned = true;
                $childErrors = array_merge($childErrors, $childReport->errors);
            }
        }

        $workerReport = $this->worker->close();

        if (!$workerReport->ok() || $this->poisoned) {
            $this->poisoned = true;
        }

        if (!$this->poisoned) {
            return $workerReport;
        }

        return new CleanupReport(
            frameId: $workerReport->frameId,
            kind: $workerReport->kind,
            resolvedInstanceCount: $workerReport->resolvedInstanceCount,
            errors: array_merge($workerReport->errors, $childErrors),
            poisoned: true,
        );
    }

    public function isPoisoned(): bool
    {
        if ($this->poisoned) {
            return true;
        }

        foreach ($this->children as $child) {
            $childReport = $child->close();
            if (!$childReport->ok()) {
                $this->poisoned = true;

                return true;
            }
        }

        return false;
    }
}
