<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Throwable;
use VoltStack\Runtime\Reset\ResetReport;

final readonly class RequestRunResult
{
    /**
     * @param object|null $scopeState Holder mutable con propiedades `resetReport` (ResetReport)
     *                             y `disposition` (WorkerDisposition); actualizado por el finalizador.
     */
    public function __construct(
        private Request $request,
        private ?Response $response,
        private WorkerDisposition $workerDisposition,
        private ResetReport $resetReport,
        private float $durationMs,
        private ?Throwable $exception = null,
        private ?\Closure $scopeFinalizer = null,
        private ?object $scopeState = null,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function response(): ?Response
    {
        return $this->response;
    }

    public function workerDisposition(): WorkerDisposition
    {
        return $this->scopeState?->disposition ?? $this->workerDisposition;
    }

    public function resetReport(): ResetReport
    {
        return $this->scopeState?->resetReport ?? $this->resetReport;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    public function successful(): bool
    {
        return $this->exception === null && $this->resetReport()->successful();
    }

    /**
     * Indica si el resultado incluye un finalizador de scope que debe invocarse
     * DESPUÉS de emitir la respuesta (necesario para streaming).
     */
    public function hasScopeFinalizer(): bool
    {
        return $this->scopeFinalizer !== null;
    }

    /**
     * Ejecuta el finalizador de scope (cierre de RuntimeContext + reset de
     * servicios scoped). Es seguro invocarlo múltiples veces; sólo se ejecuta una.
     *
     * Debe llamarse desde el adapter en su propio `finally`, después de `emit()`.
     */
    public function finalizeScope(): void
    {
        if ($this->scopeFinalizer === null) {
            return;
        }

        ($this->scopeFinalizer)();
    }
}
