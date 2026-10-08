<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Runtime;

final readonly class FinalizationOutcome
{
    /**
     * @param array<string, scalar|array|null> $metadata
     */
    public function __construct(
        public string $kind,
        public array $metadata = [],
    ) {
        if ($kind === '') {
            throw new \InvalidArgumentException('FinalizationOutcome kind must not be empty.');
        }
    }

    /**
     * @param array<string, scalar|array|null> $metadata
     */
    public static function completed(array $metadata = []): self
    {
        return new self('completed', $metadata);
    }

    /**
     * @param array<string, scalar|array|null> $metadata
     */
    public static function failed(array $metadata = []): self
    {
        return new self('failed', $metadata);
    }
}
