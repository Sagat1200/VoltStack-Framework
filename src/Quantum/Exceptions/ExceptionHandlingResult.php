<?php

declare(strict_types=1);

namespace Quantum\Exceptions;

use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Response;

final readonly class ExceptionHandlingResult
{
    public function __construct(
        public ?Response $response,
        public WorkerDisposition $workerDisposition,
        public bool $emissionStarted,
    ) {
    }
}

