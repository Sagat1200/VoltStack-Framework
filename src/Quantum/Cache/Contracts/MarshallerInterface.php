<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

use Quantum\Cache\EncodedValue;

interface MarshallerInterface
{
    public function encode(mixed $value): EncodedValue;

    public function decode(EncodedValue $value): mixed;

    public function formatId(): string;
}
