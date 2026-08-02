<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use ReflectionMethod;
use RuntimeException;

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

        throw new RuntimeException('Unsupported controller route action.');
    }

    private function resolvedFromInstanceAndMethod(object $instance, string $method): ResolvedController
    {
        $normalizedMethod = trim($method);

        if ($normalizedMethod === '') {
            throw new RuntimeException('Controller method cannot be empty.');
        }

        if (str_starts_with($normalizedMethod, '__') && $normalizedMethod !== '__invoke') {
            throw new RuntimeException('Controller method is not allowed.');
        }

        if (! method_exists($instance, $normalizedMethod)) {
            throw new RuntimeException(sprintf('Controller method [%s] does not exist.', $normalizedMethod));
        }

        $reflection = new ReflectionMethod($instance, $normalizedMethod);

        if (! $reflection->isPublic()) {
            throw new RuntimeException('Controller method must be public.');
        }

        return new ResolvedController($instance, $normalizedMethod);
    }
}