<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

enum ConfigAccessMode: string
{
    case Static = 'static';
    case Scoped = 'scoped';
}
