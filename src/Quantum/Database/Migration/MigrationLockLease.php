<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final class MigrationLockLease
{
    /**
     * @param resource $handle
     * @param \Closure(string):void $releaseCallback
     */
    public function __construct(
        private readonly string $path,
        private mixed $handle,
        private readonly \Closure $releaseCallback,
        private bool $released = false,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (is_resource($this->handle)) {
            @flock($this->handle, \LOCK_UN);
            @fclose($this->handle);
        }

        ($this->releaseCallback)($this->path);
    }

    public function __destruct()
    {
        $this->release();
    }
}
