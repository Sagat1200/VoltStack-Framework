<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * PropertyAccessor: lee y escribe propiedades SIN reflection runtime hot-path.
 * Usa closures precalculadas.
 */
interface PropertyAccessorInterface
{
    public function readValue(object $entity, CompiledPropertyMetadata $meta): mixed;

    public function writeValue(object $entity, CompiledPropertyMetadata $meta, mixed $value): void;

    /**
     * Construye entity SIN llamar constructor (para hydration).
     */
    public function newEntityWithoutConstructor(CompiledEntityMetadata $meta): object;

    /**
     * Construye entity via constructor (con args).
     */
    public function newEntityWithConstructor(CompiledEntityMetadata $meta, array $constructorArgs = []): object;
}