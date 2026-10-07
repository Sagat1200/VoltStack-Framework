<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\TransportPlan;

interface TransportMapperInterface
{
    public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan;
}
