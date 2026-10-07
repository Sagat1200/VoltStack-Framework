<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Core\HandlingResult;
use Quantum\Exceptions\Model\ReportReceipt;
use Throwable;

interface ExceptionManagerInterface
{
    public function handle(Throwable $error, ExceptionContext $context): HandlingResult;

    public function report(Throwable $error, ExceptionContext $context): ReportReceipt;
}
