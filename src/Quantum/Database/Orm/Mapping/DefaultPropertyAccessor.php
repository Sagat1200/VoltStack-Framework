<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * Implementación default: closures precomputados por entidad.
 *
 * Regla MAP-002: Reflection sólo en warmup (1 vez por property).
 * Hot path (readValue/writeValue) = O(1) array lookup + closure invocación.
 */
final class DefaultPropertyAccessor implements PropertyAccessorInterface
{
    /**
     * Closure readers: key = entityClass# . propertyName
     *
     * @var array<string,\Closure>
     */
    private static array $readers = [];

    /** @var array<string,\Closure> */
    private static array $writers = [];

    /** @var array<string,\ReflectionClass> */
    private static array $reflectionCache = [];

    /**
     * {@inheritDoc}
     */
    public function readValue(object $entity, CompiledPropertyMetadata $meta): mixed
    {
        $cls = $entity::class;
        $key = $cls . '#' . $meta->propertyName;
        if (!isset(self::$readers[$key])) {
            self::buildForProperty($cls, $meta);
        }
        $fn = self::$readers[$key];
        return $fn($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function writeValue(object $entity, CompiledPropertyMetadata $meta, mixed $value): void
    {
        $cls = $entity::class;
        $key = $cls . '#' . $meta->propertyName;
        if (!isset(self::$writers[$key])) {
            self::buildForProperty($cls, $meta);
        }
        $fn = self::$writers[$key];
        $fn($entity, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function newEntityWithoutConstructor(CompiledEntityMetadata $meta): object
    {
        $rc = self::reflectionFor($meta->entityClass);
        return $rc->newInstanceWithoutConstructor();
    }

    /**
     * {@inheritDoc}
     */
    public function newEntityWithConstructor(CompiledEntityMetadata $meta, array $constructorArgs = []): object
    {
        $rc = self::reflectionFor($meta->entityClass);
        return $rc->newInstanceArgs($constructorArgs);
    }

    // ========================== INTERNAL =====================================

    /**
     * @param class-string $entityClass
     */
    private static function buildForProperty(string $entityClass, CompiledPropertyMetadata $meta): void
    {
        $key = $entityClass . '#' . $meta->propertyName;
        $propName = $meta->propertyName;
        $access = $meta->access;
        $rc = self::reflectionFor($entityClass);

        // ====== READER ======
        $reader = null;
        if ($access?->getter !== null && $rc->hasMethod($access->getter)) {
            $getter = $access->getter;
            $reader = static function (object $e) use ($getter, $rc): mixed {
                $rm = $rc->getMethod($getter);
                $rm->setAccessible(true);
                return $rm->invoke($e);
            };
        } elseif ($access?->isPublicRead) {
            $rp = $rc->getProperty($propName);
            $rp->setAccessible(true);
            $reader = static function (object $e) use ($rp): mixed {
                return $rp->isInitialized($e) ? $rp->getValue($e) : null;
            };
        } else {
            $rp = $rc->getProperty($propName);
            $rp->setAccessible(true);
            $reader = static function (object $e) use ($rp): mixed {
                return $rp->isInitialized($e) ? $rp->getValue($e) : null;
            };
        }
        self::$readers[$key] = $reader;

        // ====== WRITER ======
        $writer = null;
        if ($access?->setter !== null && $rc->hasMethod($access->setter)) {
            $setter = $access->setter;
            $writer = static function (object $e, mixed $v) use ($setter, $rc): void {
                $rm = $rc->getMethod($setter);
                $rm->setAccessible(true);
                $rm->invoke($e, $v);
            };
        } elseif ($access?->isPublicWrite) {
            $rp = $rc->getProperty($propName);
            $rp->setAccessible(true);
            $writer = static function (object $e, mixed $v) use ($rp): void {
                $rp->setValue($e, $v);
            };
        } else {
            $rp = $rc->getProperty($propName);
            $rp->setAccessible(true);
            $writer = static function (object $e, mixed $v) use ($rp): void {
                $rp->setValue($e, $v);
            };
        }
        self::$writers[$key] = $writer;
    }

    /**
     * @param class-string $cls
     */
    private static function reflectionFor(string $cls): \ReflectionClass
    {
        if (!isset(self::$reflectionCache[$cls])) {
            self::$reflectionCache[$cls] = new \ReflectionClass($cls);
        }
        return self::$reflectionCache[$cls];
    }
}
