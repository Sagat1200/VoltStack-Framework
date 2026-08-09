<?php

declare(strict_types=1);

namespace Quantum\HttpKernel\Contracts;

use Closure;
use Quantum\Http\Request;

/**
 * @api Contrato público estable de los middlewares del HttpKernel.
 *
 * Todos los middlewares customizados de la aplicación deben implementar esta interfaz.
 * Firmas inamovibles hasta 2.x.
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): mixed;
}
