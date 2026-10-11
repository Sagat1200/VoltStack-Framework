<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Http\Request;
use Quantum\Http\Response;

/**
 * Contrato para un servidor HTTP nativo de OpenSwoole (modo secuencial, sin coroutines).
 *
 * Permite al {@see OpenSwooleRuntimeAdapter} procesar requests sin necesidad
 * de un `requestSource` en memoria. La implementación real debe envolver
 * `OpenSwoole\Http\Server` (o equivalente).
 *
 * El callback `$onRequest` recibe la {@see Request} entrante y debe devolver
 * la {@see Response} a enviar. Si el callback devuelve `null`, el servidor
 * debe detener su loop (señal de terminación del worker).
 */
interface OpenSwooleNativeServerInterface
{
    /**
     * Inicia el loop de eventos del servidor HTTP.
     *
     * Este método bloquea hasta que el servidor se detiene.
     *
     * @param callable(Request): ?Response $onRequest
     */
    public function start(callable $onRequest): void;
}
