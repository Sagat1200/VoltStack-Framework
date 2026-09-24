<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

interface NativeConnectionInterface
{
    public function raw(): object;

    public function isConnected(): bool;

    public function disconnect(): void;
}
