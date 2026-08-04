<?php

declare(strict_types=1);

namespace Quantum\Transport\Enums;

enum TransportStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Emitting = 'emitting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Disconnected = 'disconnected';
}

