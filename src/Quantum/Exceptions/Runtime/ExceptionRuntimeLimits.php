<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

final readonly class ExceptionRuntimeLimits
{
    public function __construct(
        public int $maxOccurrences = 128,
        public int $maxHandlingDepth = 2,
    ) {
        if ($maxOccurrences <= 0) {
            throw new \InvalidArgumentException('ExceptionRuntimeLimits maxOccurrences must be greater than zero.');
        }

        if ($maxHandlingDepth <= 0) {
            throw new \InvalidArgumentException('ExceptionRuntimeLimits maxHandlingDepth must be greater than zero.');
        }
    }
}
