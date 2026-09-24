<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

enum PolicyType: string
{
    case Resource = 'resource';
    case Global = 'global';
    case Context = 'context';
    case Controller = 'controller';
    case Custom = 'custom';
}
