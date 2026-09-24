<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Connection\ConnectionDefinition;

interface DriverInterface
{
    public function id(): string;

    public function connect(ConnectionDefinition $definition): NativeConnectionInterface;
}
