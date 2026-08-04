<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Events;

use DateTimeImmutable;
use Quantum\Controllers\Observability\Contracts\ControllerEventInterface;

final readonly class ControllerEvent implements ControllerEventInterface
{
    public function __construct(
        private string $name,
        private int $version,
        private string $executionId,
        private DateTimeImmutable $occurredAt,
        private int $sequence,
        private array $payload = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}

