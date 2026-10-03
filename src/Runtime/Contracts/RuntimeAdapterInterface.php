<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Contracts;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;

interface RuntimeAdapterInterface
{
    public function id(): string;

    public function capabilities(): RuntimeCapabilities;

    public function run(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int;
}
