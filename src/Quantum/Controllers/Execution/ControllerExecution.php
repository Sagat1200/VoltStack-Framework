<?php

declare(strict_types=1);

namespace Quantum\Controllers\Execution;

use Quantum\Controllers\ControllerContext;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Exceptions\ControllerAlreadyInvokedException;
use Quantum\Controllers\ResolvedController;
use Quantum\Controllers\Runtime\ControllerExecutionState;
use Quantum\Controllers\Runtime\ControllerRuntimeOptions;
use Quantum\Controllers\Runtime\ControllerShortCircuitOrigin;
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
        if ($this->diagnosticsEnabled()) {
            $this->recordTimeline('exception');
            $this->setAttribute('exception', $exception);
        }

        $this->setAttribute('exception_class', $exception::class);
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

        if ($this->diagnosticsEnabled()) {
            $this->recordTimeline($state->value);
        }
    }

    public function wasInvoked(): bool
    {
        return $this->getAttribute('controller.lifecycle.invoked', false) === true;
    }

    public function markInvoked(): void
    {
        if ($this->wasInvoked()) {
            throw new ControllerAlreadyInvokedException();
        }

        $this->setAttribute('controller.lifecycle.invoked', true);

        if ($this->diagnosticsEnabled()) {
            $this->recordTimeline('invoked');
        }
    }

    public function wasShortCircuited(): bool
    {
        return $this->getAttribute('controller.lifecycle.short_circuited', false) === true;
    }

    public function markShortCircuited(
        mixed $result = null,
        ?ControllerShortCircuitOrigin $origin = null,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->setAttribute('controller.lifecycle.short_circuited', true);

        if ($this->diagnosticsEnabled()) {
            $this->recordTimeline('short_circuited');
        }

        if ($origin !== null) {
            $this->setAttribute('controller.lifecycle.short_circuit_origin', $origin);
        }

        if (func_num_args() >= 1 && $this->diagnosticsEnabled()) {
            $this->setAttribute('controller.lifecycle.short_circuit_result', $result);
        }

        if (func_num_args() >= 3 && $this->diagnosticsEnabled()) {
            $this->setAttribute('controller.lifecycle.short_circuit_reason', $reason);
        }

        if (func_num_args() >= 4 && $this->diagnosticsEnabled()) {
            $this->setAttribute('controller.lifecycle.short_circuit_metadata', $metadata);
        }
    }

    public function shortCircuitOrigin(): ?ControllerShortCircuitOrigin
    {
        $value = $this->getAttribute('controller.lifecycle.short_circuit_origin');

        return $value instanceof ControllerShortCircuitOrigin ? $value : null;
    }

    public function shortCircuitResult(): mixed
    {
        return $this->getAttribute('controller.lifecycle.short_circuit_result');
    }

    public function shortCircuitReason(): ?string
    {
        $value = $this->getAttribute('controller.lifecycle.short_circuit_reason');

        return is_string($value) ? $value : null;
    }

    public function shortCircuitMetadata(): array
    {
        $value = $this->getAttribute('controller.lifecycle.short_circuit_metadata', []);

        return is_array($value) ? $value : [];
    }

    public function timeline(): array
    {
        if (! $this->diagnosticsEnabled()) {
            return [];
        }

        $value = $this->getAttribute('controller.lifecycle.timeline', []);

        return is_array($value) ? $value : [];
    }

    public function timelineAt(string $event): ?float
    {
        $timeline = $this->timeline();

        $value = $timeline[$event] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    public function durationBetween(string $fromEvent, string $toEvent): ?float
    {
        $from = $this->timelineAt($fromEvent);
        $to = $this->timelineAt($toEvent);

        if ($from === null || $to === null) {
            return null;
        }

        return $to - $from;
    }

    public function totalDuration(): ?float
    {
        $start = $this->timelineAt('created');
        $end = $this->timelineAt('succeeded') ?? $this->timelineAt('failed');

        if ($start === null || $end === null) {
            return null;
        }

        return $end - $start;
    }

    private function recordTimeline(string $event): void
    {
        $timeline = $this->timeline();

        if (array_key_exists($event, $timeline)) {
            return;
        }

        $timeline[$event] = microtime(true);
        $this->setAttribute('controller.lifecycle.timeline', $timeline);
    }

    private function diagnosticsEnabled(): bool
    {
        $mode = $this->runtimeOptions()?->lifecycleMode;

        if ($mode === 'production') {
            return false;
        }

        if ($mode === 'development') {
            return true;
        }

        $environment = $this->context->app()->environment();

        return $environment !== 'production' && $environment !== 'prod';
    }
}