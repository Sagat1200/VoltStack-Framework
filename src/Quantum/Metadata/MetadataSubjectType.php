<?php

declare(strict_types=1);

namespace Quantum\Metadata;

enum MetadataSubjectType: string
{
    case Route = 'route';
    case Controller = 'controller';
    case ControllerMethod = 'controller_method';
}

