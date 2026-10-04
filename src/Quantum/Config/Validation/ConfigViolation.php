<?php

declare(strict_types=1);

namespace Quantum\Config\Validation;

final readonly class ConfigViolation
{
    public function __construct(
        private string $path,
        private string $code,
        private string $message,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }
}
