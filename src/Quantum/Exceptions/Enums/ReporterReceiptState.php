<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum ReporterReceiptState: string
{
    case Accepted = 'accepted';
    case Dropped = 'dropped';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
