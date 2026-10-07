<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class FailureSnapshot
{
    /**
     * @param list<array<string, scalar|null>> $frames
     * @param list<string> $causes
     * @param array<string, scalar|array|null> $safeMetadata
     */
    public function __construct(
        public string $className,
        public string $internalMessage,
        public int|string|null $phpCode = null,
        public array $frames = [],
        public array $causes = [],
        public string $origin = 'unknown',
        public array $safeMetadata = [],
        public bool $truncated = false,
    ) {
        if ($className === '') {
            throw new \InvalidArgumentException('FailureSnapshot className must not be empty.');
        }
    }
}
