<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

final readonly class ValidationIssue
{
    /**
     * @param list<string>|null $path
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $service,
        public ?string $subject = null,
        public string $severity = 'error',
        public string $phase = 'analysis',
        public ?string $consumer = null,
        public ?string $parameter = null,
        public ?string $origin = null,
        public ?array $path = null,
        public ?string $remediation = null,
    ) {
    }
}
