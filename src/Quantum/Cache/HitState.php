<?php

declare(strict_types=1);

namespace Quantum\Cache;

enum HitState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Miss = 'miss';
}
