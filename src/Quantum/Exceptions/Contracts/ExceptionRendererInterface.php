<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\TransportPlan;

interface ExceptionRendererInterface
{
    public function render(PublicError $error, TransportPlan $plan): RenderedOutput;
}
