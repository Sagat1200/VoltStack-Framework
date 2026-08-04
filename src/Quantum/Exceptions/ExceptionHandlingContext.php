<?php

declare(strict_types=1);

namespace Quantum\Exceptions;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Exceptions\Enums\ExceptionOrigin;
use Quantum\Exceptions\Runtime\ExceptionHandlingState;
use Quantum\Exceptions\Runtime\RuntimeContext;
use Quantum\Http\Request;
use Quantum\Metadata\MetadataBag;
use Quantum\Transport\Runtime\TransportExecution;
use Throwable;

final readonly class ExceptionHandlingContext
{
    public function __construct(
        public Throwable $throwable,
        public ExceptionOrigin $origin,
        public RuntimeContext $runtime,
        public ?Request $request,
        public ?ControllerExecution $controllerExecution,
        public ?TransportExecution $transportExecution,
        public MetadataBag $metadata,
        public ExceptionHandlingState $state,
        public bool $debug,
    ) {
    }
}

