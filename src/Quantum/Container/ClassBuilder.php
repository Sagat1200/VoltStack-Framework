<?php

declare(strict_types=1);

namespace Quantum\Container;

use Quantum\Container\Exceptions\BindingResolutionException;
use ReflectionClass;

/**
 * @internal
 */
final class ClassBuilder
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function build(string $concrete, array $parameters, callable $parameterResolver): object
    {
        if (! class_exists($concrete)) {
            throw new BindingResolutionException(sprintf('Target class [%s] does not exist.', $concrete));
        }

        $reflector = new ReflectionClass($concrete);

        if (! $reflector->isInstantiable()) {
            throw new BindingResolutionException(sprintf('Target [%s] is not instantiable.', $concrete));
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $parameterResolver($parameter, $parameters);
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
