<?php

declare(strict_types=1);

namespace Quantum\Controllers\Execution;

use Quantum\Controllers\ControllerContext;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\ResolvedController;
use Quantum\Controllers\Runtime\ControllerExecutionState;
use Quantum\Controllers\Runtime\ControllerRuntimeOptions;
use Throwable;

final class ControllerExecution
{
    public function __construct(
        private readonly ControllerDefinition $definition,
        private readonly ControllerContext $context,
        private readonly ResolvedController $controller,
        private array $arguments,
        private readonly ControllerExecutionContext $executionContext,
        private array $attributes = [],
    ) {}

    public function definition(): ControllerDefinition
    {
        return $this->definition;
    }

    public function context(): ControllerContext
    {
        return $this->context;
    }

    public function controller(): ResolvedController
    {
        return $this->controller;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    public function executionContext(): ControllerExecutionContext
    {
        return $this->executionContext;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function forgetAttribute(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function recordException(Throwable $exception): void
    {
        $this->setAttribute('exception', $exception);
    }

    public function runtimeOptions(): ?ControllerRuntimeOptions
    {
        $value = $this->getAttribute('controller.runtime');

        return $value instanceof ControllerRuntimeOptions ? $value : null;
    }

    public function state(): ?ControllerExecutionState
    {
        $value = $this->getAttribute('controller.lifecycle.state');

        return $value instanceof ControllerExecutionState ? $value : null;
    }

    public function setState(ControllerExecutionState $state): void
    {
        $this->setAttribute('controller.lifecycle.state', $state);
    }
}
