<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Contracts;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Context\BootstrapContext;
use VoltStack\Framework\Application;

interface BootstrapWarmerInterface
{
    public function warm(Application $app, ApplicationPlan $plan, ?BootstrapContext $context = null): void;
}
