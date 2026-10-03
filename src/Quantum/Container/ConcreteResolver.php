<?php

declare(strict_types=1);

namespace Quantum\Container;

use Closure;
use Quantum\Container\Contracts\ContainerInterface;
use Quantum\Container\Exceptions\BindingResolutionException;

/**
 * @internal
 */
final class ConcreteResolver
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function resolve(
        mixed $concrete,
        array $parameters,
        ContainerInterface $container,
        callable $classBuilder,
    ): mixed {
        if ($concrete instanceof Closure) {
            return $concrete($container, $parameters);
        }

        if (is_string($concrete)) {
            return $classBuilder($concrete, $parameters);
        }

        if (is_object($concrete)) {
            return $concrete;
        }

        throw new BindingResolutionException('Unable to resolve the given binding.');
    }
}
