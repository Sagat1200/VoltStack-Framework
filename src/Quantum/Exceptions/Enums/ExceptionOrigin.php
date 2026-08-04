<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum ExceptionOrigin: string
{
    case Bootstrap = 'bootstrap';
    case Routing = 'routing';
    case ControllerResolution = 'controller_resolution';
    case ParameterResolution = 'parameter_resolution';
    case Interceptor = 'interceptor';
    case Invocation = 'invocation';
    case Transformation = 'transformation';
    case Rendering = 'rendering';
    case Hydration = 'hydration';
    case TransportPreparation = 'transport_preparation';
    case TransportEmission = 'transport_emission';
    case Streaming = 'streaming';
    case Shutdown = 'shutdown';
    case Worker = 'worker';
    case Unknown = 'unknown';
}

