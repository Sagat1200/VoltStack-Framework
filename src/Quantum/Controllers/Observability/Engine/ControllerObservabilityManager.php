<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Engine;

use DateTimeImmutable;
use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerObservabilityManagerInterface;
use Quantum\Controllers\Observability\Events\ControllerEvent;
use Quantum\Controllers\Observability\Events\EventSequence;
use Throwable;

final class ControllerObservabilityManager implements ControllerObservabilityManagerInterface
{
    public function __construct(
        private readonly ControllerEventDispatcherInterface $events,
    ) {
    }

    public function emit(string $name, ControllerExecution $execution, array $payload = [], int $version = 1): void
    {
        $executionId = $execution->executionId();

        if ($executionId === null) {
            return;
        }

        $sequence = $execution->getAttribute('controller.observability.sequence');

        if (! $sequence instanceof EventSequence) {
            $sequence = new EventSequence();
            $execution->setAttribute('controller.observability.sequence', $sequence);
        }

        $event = new ControllerEvent(
            name: $name,
            version: $version,
            executionId: $executionId,
            occurredAt: new DateTimeImmutable(),
            sequence: $sequence->next(),
            payload: $payload,
        );

        try {
            $this->events->dispatch($event);
        } catch (Throwable) {
            $execution->setAttribute('controller.observability.failed', true);
        }
    }
}

