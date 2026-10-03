<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Contracts;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\WorkerSession;

interface WorkerFactoryInterface
{
    public function create(ApplicationPlan $plan, WorkerContext $context): WorkerSession;
}
