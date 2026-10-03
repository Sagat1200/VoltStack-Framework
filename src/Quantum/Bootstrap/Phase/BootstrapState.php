<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Phase;

enum BootstrapState: string
{
    case New = 'NEW';
    case Discovering = 'DISCOVERING';
    case Registering = 'REGISTERING';
    case Configuring = 'CONFIGURING';
    case Compiling = 'COMPILING';
    case Booting = 'BOOTING';
    case Warming = 'WARMING';
    case Ready = 'READY';
    case Draining = 'DRAINING';
    case Stopping = 'STOPPING';
    case Failed = 'FAILED';
    case Stopped = 'STOPPED';
}
