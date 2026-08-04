<?php

declare(strict_types=1);

namespace Quantum\Transport\ResponseBody;

use Quantum\Transport\Contracts\ResponseBodyInterface;
use Quantum\Transport\Enums\ResponseBodyType;

final readonly class EmptyResponseBody implements ResponseBodyInterface
{
    public function type(): ResponseBodyType
    {
        return ResponseBodyType::Empty;
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function isReplayable(): bool
    {
        return true;
    }

    public function length(): ?int
    {
        return 0;
    }
}

