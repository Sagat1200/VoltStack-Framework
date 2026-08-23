<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

abstract class AbstractFactory implements FactoryInterface
{
    public function description(): string
    {
        return static::class;
    }

    public function states(): array
    {
        return [];
    }

    public function instantiate(array $attributes, FactoryContext $context): object
    {
        $entityClass = $this->entityClass();
        $reflection = new \ReflectionClass($entityClass);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(sprintf(
                'Factory [%s] must override instantiate() because [%s] requires constructor arguments.',
                static::class,
                $entityClass,
            ));
        }

        $entity = $reflection->newInstance();

        foreach ($attributes as $property => $value) {
            if (!$reflection->hasProperty($property)) {
                continue;
            }

            $refProperty = $reflection->getProperty($property);
            $refProperty->setAccessible(true);
            $refProperty->setValue($entity, $value);
        }

        return $entity;
    }
}
