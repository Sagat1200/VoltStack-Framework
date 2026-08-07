<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security;

enum ControllerTargetType: string
{
    case ControllerMethod = 'controller_method';
    case InvokableController = 'invokable_controller';
    case Action = 'action';
    case ServiceMethod = 'service_method';
    case Closure = 'closure';
    case Page = 'page';
    case Component = 'component';
    case Resource = 'resource';
}
