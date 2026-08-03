<?php

declare(strict_types=1);

namespace Quantum\Metadata;

enum MetadataMergeStrategy: string
{
    case Replace = 'replace';
    case Append = 'append';
}

