<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class RenderedOutput
{
    /**
     * @param array<string, string> $safeHeaders
     */
    public function __construct(
        public string $target,
        public string $bodyBytes,
        public string $mediaType,
        public ?int $status = null,
        public ?int $exitCode = null,
        public array $safeHeaders = [],
    ) {
        if ($target === '') {
            throw new \InvalidArgumentException('RenderedOutput target must not be empty.');
        }

        if ($mediaType === '') {
            throw new \InvalidArgumentException('RenderedOutput mediaType must not be empty.');
        }
    }
}
