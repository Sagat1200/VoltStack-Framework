<?php

declare(strict_types=1);

namespace Quantum\Transport\Response;

final readonly class ResponseMetadata
{
    public function __construct(
        public array $headers = [],
    ) {
    }

    public function header(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self(headers: $headers);
    }
}

