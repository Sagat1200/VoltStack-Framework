<?php

declare(strict_types=1);

namespace Quantum\Authorization\Principal;

enum PrincipalType: string
{
    case Anonymous = 'anonymous';
    case User = 'user';
    case Service = 'service';
    case ApiClient = 'api_client';
    case System = 'system';
    case ImpersonatedUser = 'impersonated_user';
}
