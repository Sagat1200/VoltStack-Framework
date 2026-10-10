<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Recovery\RecoveryCode;

/**
 * Storage pluggable para recovery codes asociados a identidades.
 *
 * Garantías obligatorias:
 * - One-time: después de `consume()` un código que retorna `!== null` ya NO debe
 *   volver a ser retornado en `findUsableByCode()` ni `listForIdentity()`
 *   debe marcarse consumido de forma atómica.
 * - `verify()` sólo valida código sin marcarlo; `consume()` marca.
 * - `attachBatch()` es un append de códigos a una misma identidad (no debe borra los
 *   códigos previos, para conservar packs generados en rotaciones.
 * - `rotateForIdentity()` invalida todos los códigos `(consumed=true o no).
 */
interface RecoveryCodeStoreInterface
{
    /**
     * Crea/almacena uno o varios recovery codes para la identidad indicada.
     *
     * @param iterable<RecoveryCode> $codes
     *
     * @return list<RecoveryCode> los códigos persistidos (sin la propiedad plain `code`
     *   ya que el store no guarda únicamente codeHash a partir de este punto.
     */
    public function attachBatch(IdentityReference $identity, iterable $codes): array;

    /**
     * Devuelve el listado completo de códigos asociados a la identidad (consumidos y no consumidos.
     *
     * @return list<RecoveryCode>
     */
    public function listForIdentity(IdentityReference $identity): array;

    /**
     * Busca un código usable (no consumido, no expirado, perteneciente a la identidad)
     * por su representación textual (plain). La comparación debe ser a nivel hash y en
     * constante en código de forma equivalente a `hash_equals` o `password_verify`
     * (dependiendo del algoritmo usado al generar `codeHash`).
     *
     * Retorna `null` si no hay coincidencia usable.
     */
    public function findUsableByCode(IdentityReference $identity, string $plainCode, ?int $now = null): ?RecoveryCode;

    /**
     * Marca el código proporcionado como consumido atómicamente; retorna la instancia
     * actualizada o `null` si el código ya no estaba disponible.
     */
    public function consume(RecoveryCode $code, ?int $now = null): ?RecoveryCode;

    /**
     * Invalida todos los códigos de la identidad (usados o no). Usar tras password reset completo,
     * revocación de MFA, cambio de credencial MFA, etc.
     *
     * @return int número de registros invalidados.
     */
    public function rotateForIdentity(IdentityReference $identity, ?int $now = null): int;
}
