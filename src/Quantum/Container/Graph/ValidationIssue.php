<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

final readonly class ValidationIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public string $service,
        public ?string $subject = null,
    ) {
    }
}
