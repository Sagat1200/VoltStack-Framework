<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

final readonly class PhpShutdownRenderResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }
}
