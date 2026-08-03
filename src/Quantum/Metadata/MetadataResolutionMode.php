<?php

declare(strict_types=1);

namespace Quantum\Metadata;

enum MetadataResolutionMode: string
{
    case Runtime = 'runtime';
    case Compiled = 'compiled';
}

