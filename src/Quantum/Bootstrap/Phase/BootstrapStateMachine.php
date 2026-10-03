<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Phase;

use LogicException;

final class BootstrapStateMachine
{
    private BootstrapState $current;

    public function __construct(?BootstrapState $initialState = null)
    {
        $this->current = $initialState ?? BootstrapState::New;
    }

    public function current(): BootstrapState
    {
        return $this->current;
    }

    public function transitionTo(BootstrapState $next): void
    {
        if (! $this->canTransitionTo($next)) {
            throw new LogicException(sprintf(
                'Invalid bootstrap state transition from [%s] to [%s].',
                $this->current->value,
                $next->value,
            ));
        }

        $this->current = $next;
    }

    public function canTransitionTo(BootstrapState $next): bool
    {
        $allowed = match ($this->current) {
            BootstrapState::New => [BootstrapState::Discovering, BootstrapState::Failed],
            BootstrapState::Discovering => [BootstrapState::Registering, BootstrapState::Failed],
            BootstrapState::Registering => [BootstrapState::Configuring, BootstrapState::Failed],
            BootstrapState::Configuring => [BootstrapState::Compiling, BootstrapState::Failed],
            BootstrapState::Compiling => [BootstrapState::Booting, BootstrapState::Failed, BootstrapState::Stopped],
            BootstrapState::Booting => [BootstrapState::Warming, BootstrapState::Failed],
            BootstrapState::Warming => [BootstrapState::Ready, BootstrapState::Failed],
            BootstrapState::Ready => [BootstrapState::Draining, BootstrapState::Failed],
            BootstrapState::Draining => [BootstrapState::Stopping, BootstrapState::Failed],
            BootstrapState::Stopping => [BootstrapState::Stopped, BootstrapState::Failed],
            BootstrapState::Failed => [BootstrapState::Stopped],
            BootstrapState::Stopped => [],
        };

        return in_array($next, $allowed, true);
    }
}
