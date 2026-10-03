<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Smoke;

use Quantum\Exceptions\Enums\WorkerDisposition;
use VoltStack\Runtime\RequestRunResult;

final readonly class RuntimeSmokeRequestReport
{
    public function __construct(
        private string $method,
        private string $path,
        private ?int $statusCode,
        private float $durationMs,
        private bool $successful,
        private WorkerDisposition $workerDisposition,
        private ?string $exceptionMessage = null,
    ) {
    }

    public static function fromRunResult(RequestRunResult $result): self
    {
        return new self(
            method: $result->request()->method(),
            path: $result->request()->path(),
            statusCode: $result->response()?->statusCode(),
            durationMs: $result->durationMs(),
            successful: $result->successful(),
            workerDisposition: $result->workerDisposition(),
            exceptionMessage: $result->exception()?->getMessage(),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function durationMs(): float
    {
        return $this->durationMs;
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    public function workerDisposition(): WorkerDisposition
    {
        return $this->workerDisposition;
    }

    public function exceptionMessage(): ?string
    {
        return $this->exceptionMessage;
    }

    public function passed(): bool
    {
        return $this->successful
            && $this->statusCode !== null
            && $this->statusCode < 400
            && $this->workerDisposition !== WorkerDisposition::Terminate;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'successful' => $this->successful,
            'passed' => $this->passed(),
            'worker_disposition' => $this->workerDisposition->value,
            'exception_message' => $this->exceptionMessage,
        ];
    }
}
