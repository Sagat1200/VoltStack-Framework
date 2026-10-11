<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

/**
 * Store de contadores para rate-limit por principal.
 *
 * La implementacion por defecto es in-memory (scoped). Para entornos
 * multi-worker se debe inyectar una implementacion basada en un store
 * compartido (ej. Quantum/Cache sobre Redis).
 */
interface RateLimitStoreInterface
{
    /**
     * Incrementa el contador para la clave indicada dentro de la ventana.
     *
     * Si la ventana expiro, reinicia el contador a 1.
     *
     * @return int El valor del contador despues del incremento.
     */
    public function increment(string $key, int $windowSeconds): int;

    /**
     * Reinicia el contador para la clave indicada.
     */
    public function reset(string $key): void;
}
