<?php

declare(strict_types=1);

namespace Quantum\Routing\Dispatching;

use JsonSerializable;
use Quantum\Http\JsonResponse;
use Quantum\Http\Response;
use Quantum\View\View;
use Stringable;
use VoltStack\Runtime\Component\Component;
use VoltStack\Runtime\Component\ComponentManager;

/**
 * @api Normaliza cualquier output de controller/middleware a `Quantum\Http\Response`.
 *
 * Tipos soportados oficialmente (orden prioridad):
 *   - `Quantum\Http\Response` (y subclases JsonResponse/RedirectResponse) → devuelto tal cual
 *   - `array` → `JsonResponse` con status 200
 *   - `\JsonSerializable` → `JsonResponse` con status 200
 *   - `Quantum\View\View` → Response HTML con el renderizado de la view
 *   - `VoltStack\Runtime\Component\Component` → Response HTML con renderRoot()
 *   - `\Stringable` / `string` / `numeric` → Response text/plain
 *   - `bool` → `true` = JSON {"ok":true} 200 / `false` = JSON {"ok":false} 200
 *   - `null` → Response vacío 200 OK (no 204 No Content para evitar ambigüedad con navegadores)
 *   - `resource` o cualquier otro tipo → fallback `JsonResponse` normalizado (no lanza \TypeError, no 500 ambiguo)
 *
 * No cambies estas reglas sin incrementar MINOR: apps consumidoras dependen de los tipos soportados.
 */
final class ResponseNormalizer
{
    public function __construct(private readonly ComponentManager $components) {}

    public function normalize(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if (is_array($response)) {
            return new JsonResponse($response);
        }

        if ($response instanceof JsonSerializable) {
            return new JsonResponse($response->jsonSerialize());
        }

        if ($response instanceof View) {
            return new Response($response->render());
        }

        if ($response instanceof Component) {
            return new Response($this->components->renderRoot($response));
        }

        if (is_string($response) || is_numeric($response)) {
            return new Response((string) $response);
        }

        if ($response instanceof Stringable) {
            return new Response($response->__toString());
        }

        if (is_bool($response)) {
            return new JsonResponse(['ok' => $response]);
        }

        if ($response === null) {
            return new Response('');
        }

        if (is_resource($response)) {
            $meta = stream_get_meta_data($response);
            $type = $meta['mediatype'] ?? mime_content_type($response) ?: 'application/octet-stream';
            $contents = stream_get_contents($response) ?: '';
            if (is_resource($response)) {
                @fclose($response);
            }
            return new Response($contents, 200, ['Content-Type' => $type]);
        }

        // Fallback final: no lanzamos \TypeError → devolvemos JsonResponse normalizado.
        // Esto previene 500 ambiguos cuando un controller devuelve objeto/resource no esperado.
        $fallback = [
            '__normalized_type' => get_debug_type($response),
        ];
        if (is_object($response) && method_exists($response, '__toString')) {
            $fallback['__normalized_value'] = (string) $response;
        }
        return new JsonResponse($fallback);
    }
}