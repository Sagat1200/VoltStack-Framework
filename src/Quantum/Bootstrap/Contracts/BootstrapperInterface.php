<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Contracts;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\BootstrapResult;
use Quantum\Bootstrap\Context\BootstrapContext;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

interface BootstrapperInterface
{
    /**
     * @param array<int, class-string<ServiceProvider>|ServiceProvider> $providers
     */
    public function bootstrap(array $providers = []): Application;

    public function bootstrapPlan(ApplicationPlan $plan, ?BootstrapContext $context = null): Application;

    public function bootPlan(ApplicationPlan $plan, ?BootstrapContext $context = null): BootstrapResult;
}
