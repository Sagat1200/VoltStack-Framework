<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Metadata\AuthorizationMetadata;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\RouteMatch;

interface AuthorizationMetadataResolverInterface
{
    public function resolve(RouteMatch $match, ?ControllerDefinition $definition = null): AuthorizationMetadata;
}
