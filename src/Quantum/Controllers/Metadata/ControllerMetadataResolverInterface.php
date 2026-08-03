<?php

declare(strict_types=1);

namespace Quantum\Controllers\Metadata;

use Quantum\Controllers\Execution\ControllerExecution;

interface ControllerMetadataResolverInterface
{
    public function resolve(ControllerExecution $execution): ControllerMetadata;
}

