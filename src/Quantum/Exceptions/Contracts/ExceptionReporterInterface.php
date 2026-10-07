<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\ReporterReceipt;

interface ExceptionReporterInterface
{
    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt;
}
