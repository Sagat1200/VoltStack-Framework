<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Engine;

use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;

final class InMemoryControllerEventDispatcher implements ControllerEventDispatcherInterface
{
    /**
     * @var array<int, ControllerEventInterface>
     */
    private array $events = [];

    public function __construct(private readonly int $maxEvents = 1000) {}

    public function dispatch(ControllerEventInterface $event): void
    {
        $this->events[] = $event;

        if (count($this->events) <= $this->maxEvents) {
            return;
        }

        $excess = count($this->events) - $this->maxEvents;

        if ($excess <= 0) {
            return;
        }

        $this->events = array_slice($this->events, $excess);
    }

    /**
     * @return array<int, ControllerEventInterface>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function last(): ?ControllerEventInterface
    {
        $last = $this->events[array_key_last($this->events)] ?? null;

        return $last instanceof ControllerEventInterface ? $last : null;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
