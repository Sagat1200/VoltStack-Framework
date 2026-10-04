<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\MarshallerInterface;
use RuntimeException;

final class PhpSerializeMarshaller implements MarshallerInterface
{
    public function encode(mixed $value): EncodedValue
    {
        $bytes = serialize($value);

        if (! is_string($bytes)) {
            throw new RuntimeException('Unable to serialize cache value.');
        }

        return new EncodedValue(
            formatId: $this->formatId(),
            schemaVersion: 1,
            bytes: $bytes,
        );
    }

    public function decode(EncodedValue $value): mixed
    {
        return unserialize($value->bytes);
    }

    public function formatId(): string
    {
        return 'php-serialize-v1';
    }
}
