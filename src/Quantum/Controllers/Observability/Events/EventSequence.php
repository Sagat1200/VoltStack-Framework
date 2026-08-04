<?php

declare(strict_types=1);

namespace Quantum\Controllers\Observability\Events;

final class EventSequence
{
    private int $current = 0;

    public function next(): int
    {
        $this->current++;

        return $this->current;
    }
}

