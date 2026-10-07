<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Model\FailureSnapshot;
use Throwable;

interface ExceptionNormalizerInterface
{
    public function normalize(Throwable $error, ExceptionContext $context): FailureSnapshot;
}
