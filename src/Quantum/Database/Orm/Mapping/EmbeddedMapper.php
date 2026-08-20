<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

/**
 * EmbeddedMapper: deshidrata / hidrata ValueObjects marcados con #[Embedded].
 *
 * V1 simple:
 *  - extractEmbedded(object $vo): array<string,mixed> public properties + getters via reflection 1 vez
 *  - hydrateEmbedded(array $row, string $prefix, string $voClass): object $vo via reflection constructor con named args.
 *
 * El manejo de column prefix con naming strategy, deep nesting y partial denormalized
 * se implementa en DDD-07 / DDD-08.
 */
final class EmbeddedMapper
{
    /**
     * @return array<string,mixed> nombrePropiedad → valorPHP
     */
    public function extractEmbedded(object $vo): array
    {
        $rc = new \ReflectionClass($vo);
        $out = [];
        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $rp) {
            $rp->setAccessible(true);
            $out[$rp->getName()] = $rp->getValue($vo);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @template T of object
     * @param class-string<T> $voClass
     * @return T
     */
    public function hydrateEmbedded(array $row, string $prefix, string $voClass): object
    {
        $rc = new \ReflectionClass($voClass);
        $constructor = $rc->getConstructor();
        if ($constructor === null) {
            $vo = $rc->newInstanceWithoutConstructor();
            // populate via reflection
            foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $rp) {
                $key = $prefix . $rp->getName();
                if (array_key_exists($key, $row)) {
                    $rp->setAccessible(true);
                    $rp->setValue($vo, $row[$key]);
                }
            }
            return $vo;
        }

        // Named args vía constructor
        $args = [];
        foreach ($constructor->getParameters() as $p) {
            $name = $p->getName();
            $key = $prefix . $name;
            if (array_key_exists($key, $row)) {
                $args[$name] = $row[$key];
            } elseif ($p->isOptional() || $p->allowsNull()) {
                $args[$name] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
            } else {
                $args[$name] = null;
            }
        }
        return $rc->newInstanceArgs($args);
    }
}
