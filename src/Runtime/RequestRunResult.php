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
    public function __construct(
        private Request $request,
        private ?Response $response,
        private WorkerDisposition $workerDisposition,
        private ResetReport $resetReport,
        private float $durationMs,
        private ?Throwable $exception = null,
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
        return $this->workerDisposition;
    }

    public function resetReport(): ResetReport
    {
        return $this->resetReport;
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
        return $this->exception === null && $this->resetReport->successful();
    }
}
