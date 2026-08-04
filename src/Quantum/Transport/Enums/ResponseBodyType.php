<?php

declare(strict_types=1);

namespace Quantum\Transport\Enums;

enum ResponseBodyType: string
{
    case Empty = 'empty';
    case Text = 'text';
}
