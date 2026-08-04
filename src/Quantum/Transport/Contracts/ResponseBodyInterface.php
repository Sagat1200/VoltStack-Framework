<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Enums\ResponseBodyType;

interface ResponseBodyInterface
{
    public function type(): ResponseBodyType;

    public function isEmpty(): bool;

    public function isReplayable(): bool;

    public function length(): ?int;
}

