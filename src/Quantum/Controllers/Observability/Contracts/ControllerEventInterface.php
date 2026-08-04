<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Contracts;

use DateTimeImmutable;

interface ControllerEventInterface
{
    public function name(): string;

    public function version(): int;

    public function executionId(): string;

    public function occurredAt(): DateTimeImmutable;

    public function sequence(): int;

    public function payload(): array;
}

