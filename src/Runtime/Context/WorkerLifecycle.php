<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Context;

use Quantum\Exceptions\Enums\WorkerDisposition;

final class WorkerLifecycle
{
    private bool $terminate = false;
    private bool $reset = false;
    private ?string $lastDisposition = null;

    public function request(WorkerDisposition $disposition): void
    {
        $this->lastDisposition = $disposition->value;

        if ($disposition === WorkerDisposition::Terminate) {
            $this->terminate = true;
        }

        if ($disposition === WorkerDisposition::Reset) {
            $this->reset = true;
        }
    }

    public function shouldTerminate(): bool
    {
        return $this->terminate;
    }

    public function shouldReset(): bool
    {
        return $this->reset;
    }

    public function lastDisposition(): ?string
    {
        return $this->lastDisposition;
    }
}
