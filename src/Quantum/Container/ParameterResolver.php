<?php

declare(strict_types=1);

namespace Quantum\Container;

use Quantum\Container\Exceptions\BindingResolutionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @internal
 */
final class ParameterResolver
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function resolve(ReflectionParameter $parameter, array $parameters, callable $serviceResolver): mixed
    {
        if (array_key_exists($parameter->getName(), $parameters)) {
            return $parameters[$parameter->getName()];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $typeName = $type->getName();

            if (array_key_exists($typeName, $parameters)) {
                return $parameters[$typeName];
            }

            return $serviceResolver($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new BindingResolutionException(sprintf(
            'Unable to resolve dependency [%s] in class [%s].',
            $parameter->getName(),
            (string) $parameter->getDeclaringClass()?->getName(),
        ));
    }
}
