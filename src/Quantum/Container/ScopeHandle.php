<?php

declare(strict_types=1);

namespace Quantum\Container;

use LogicException;

/**
 * Handle de un scope activo dentro del contenedor.
 *
 * Un handle es de un solo uso para `run()`: tras ejecutar el callback se cierra
 * automaticamente. `close()` puede invocarse manualmente y retorna siempre un
 * `CleanupReport` (nunca lanza por fallos de cleanup), permitiendo cerrar todos
 * los niveles en un `finally`.
 *
 * Estados: NEW (antes de construir) → ACTIVE → CLOSED.
 *
 * @internal
 */
final class ScopeHandle
{
    private const STATE_ACTIVE = 'active';
    private const STATE_CLOSED = 'closed';

    private string $state = self::STATE_ACTIVE;
    private bool $ran = false;
    private bool $poisoned = false;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param null|callable(CleanupReport): void $onClose Callback opcional invocado tras cerrar el scope, con el reporte resultante.
     */
    public function __construct(
        private readonly ScopeStack $stack,
        private readonly ScopeFrame $frame,
        private readonly mixed $onClose = null,
    ) {
    }

    public function token(): ScopeToken
    {
        return ScopeToken::fromFrame($this->frame);
    }

    public function kind(): ScopeKind
    {
        return $this->frame->kind();
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function seed(string $id, mixed $value): void
    {
        $this->assertActive();
        $this->frame->seed($id, $value);
    }

    public function hasSeed(string $id): bool
    {
        return $this->frame->hasSeed($id);
    }

    public function getSeed(string $id): mixed
    {
        return $this->frame->getSeed($id);
    }

    /**
     * Ejecuta una operación dentro del scope y lo cierra al terminar.
     *
     * El callback recibe el `ScopeToken` del frame activo. Si el callback falla,
     * se relanza el error original y el handle queda marcado como poisoned.
     *
     * @throws LogicException si el handle ya fue ejecutado o cerrado.
     */
    public function run(callable $operation): mixed
    {
        if ($this->ran) {
            throw new LogicException('ScopeHandle::run() can only be called once per handle.');
        }

        $this->ran = true;
        $this->assertActive();

        try {
            return $operation($this->token());
        } catch (\Throwable $throwable) {
            $this->errors[] = $throwable->getMessage();
            $this->poisoned = true;

            throw $throwable;
        } finally {
            $this->close();
        }
    }

    /**
     * Cierra el scope y retorna un reporte de cleanup.
     *
     * Es un no-op observable si el scope ya fue cerrado: retorna el mismo reporte.
     */
    public function close(): CleanupReport
    {
        if ($this->state === self::STATE_CLOSED) {
            return $this->buildReport();
        }

        $this->state = self::STATE_CLOSED;

        try {
            $this->stack->leave();
        } catch (\Throwable $throwable) {
            $this->errors[] = $throwable->getMessage();
            $this->poisoned = true;
        }

        $report = $this->buildReport();

        if ($this->onClose !== null) {
            try {
                ($this->onClose)($report);
            } catch (\Throwable $throwable) {
                $this->errors[] = $throwable->getMessage();
                $this->poisoned = true;
            }

            return $this->buildReport();
        }

        return $report;
    }

    private function buildReport(): CleanupReport
    {
        return new CleanupReport(
            frameId: $this->frame->id(),
            kind: $this->frame->kind(),
            resolvedInstanceCount: $this->frame->resolvedInstanceCount(),
            errors: $this->errors,
            poisoned: $this->poisoned,
        );
    }

    private function assertActive(): void
    {
        if ($this->state !== self::STATE_ACTIVE) {
            throw new LogicException(sprintf('Scope handle is not active (state: %s).', $this->state));
        }
    }
}
