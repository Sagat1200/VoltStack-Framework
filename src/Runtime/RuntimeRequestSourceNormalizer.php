<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Iterator;
use Quantum\Http\Request;
use Traversable;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;

final class RuntimeRequestSourceNormalizer
{
    /**
     * @return Iterator<int, Request>
     */
    public static function normalize(mixed $source, string $driverLabel): Iterator
    {
        if ($source === null) {
            return self::yieldRequests([], $driverLabel);
        }

        if (is_callable($source)) {
            $source = $source();
        }

        if (! is_array($source) && ! $source instanceof Traversable) {
            throw new RuntimeAdapterException(sprintf(
                '%s runtime request source must be iterable or callable.',
                $driverLabel,
            ));
        }

        return self::yieldRequests($source, $driverLabel);
    }

    /**
     * @param iterable<mixed> $source
     * @return Iterator<int, Request>
     */
    private static function yieldRequests(iterable $source, string $driverLabel): Iterator
    {
        foreach ($source as $request) {
            if (! $request instanceof Request) {
                throw new RuntimeAdapterException(sprintf(
                    '%s runtime request source must yield Request instances.',
                    $driverLabel,
                ));
            }

            yield $request;
        }
    }
}
