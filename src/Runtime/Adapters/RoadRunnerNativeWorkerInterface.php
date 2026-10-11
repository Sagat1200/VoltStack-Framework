<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Http\Request;
use Quantum\Http\Response;

/**
 * Contrato para un worker HTTP nativo de RoadRunner.
 *
 * Permite al {@see RoadRunnerRuntimeAdapter} procesar requests sin necesidad
 * de un `requestSource` en memoria. La implementación real debe envolver
 * `Spiral\RoadRunner\Worker` (o equivalente).
 */
interface RoadRunnerNativeWorkerInterface
{
    /**
     * Espera la siguiente request HTTP del worker de RoadRunner.
     *
     * @return Request|null La request, o null si el worker se cerró o no hay
     *                      más requests pendientes.
     */
    public function waitRequest(): ?Request;

    /**
     * Emite la respuesta HTTP de vuelta al worker de RoadRunner.
     */
    public function respond(Response $response): void;
}
