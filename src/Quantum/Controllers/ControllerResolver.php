<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\Exceptions\ControllerMethodNotAllowedException;
use Quantum\Controllers\Exceptions\ControllerMethodNotFoundException;
use Quantum\Controllers\Exceptions\ControllerMethodNotPublicException;
use Quantum\Controllers\Exceptions\InvalidControllerMethodException;
use Quantum\Controllers\Exceptions\UnsupportedControllerActionException;
use ReflectionMethod;

final class ControllerResolver
{
    public function resolve(ControllerDefinition $definition, ControllerContext $context): ResolvedController
    {
        $action = $definition->action();

        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;

            return $this->resolvedFromInstanceAndMethod(
                is_object($class) ? $class : $context->app()->make((string) $class),
                (string) $method,
            );
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return $this->resolvedFromInstanceAndMethod(
                $context->app()->make($class),
                $method,
            );
        }

        if (is_string($action) && class_exists($action)) {
            $instance = $context->app()->make($action);

            return $this->resolvedFromInstanceAndMethod($instance, '__invoke');
        }

        throw new UnsupportedControllerActionException();
    }

    private function resolvedFromInstanceAndMethod(object $instance, string $method): ResolvedController
    {
        $normalizedMethod = trim($method);

        if ($normalizedMethod === '') {
            throw new InvalidControllerMethodException();
        }

        if (str_starts_with($normalizedMethod, '__') && $normalizedMethod !== '__invoke') {
            throw new ControllerMethodNotAllowedException();
        }

        if (! method_exists($instance, $normalizedMethod)) {
            throw new ControllerMethodNotFoundException($normalizedMethod);
        }

        $reflection = new ReflectionMethod($instance, $normalizedMethod);

        if (! $reflection->isPublic()) {
            throw new ControllerMethodNotPublicException();
        }

        return new ResolvedController($instance, $normalizedMethod);
    }
}
