<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use JsonSerializable;

final readonly class ValidationIssue implements JsonSerializable
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'phase' => $this->phase,
            'service_id' => $this->service,
            'consumer_id' => $this->consumer,
            'parameter' => $this->parameter,
            'origin' => $this->origin,
            'path' => $this->path,
            'message' => $this->message,
            'subject' => $this->subject,
            'remediation' => $this->remediation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
