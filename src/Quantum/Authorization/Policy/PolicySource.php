<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy;

enum PolicySource: string
{
    case Explicit = 'explicit';
    case Attribute = 'attribute';
    case Config = 'config';
    case Convention = 'convention';
}
