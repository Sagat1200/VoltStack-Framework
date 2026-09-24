<?php

declare(strict_types=1);

namespace Quantum\Authorization\Context;

final readonly class AuthorizationContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private string $requestId,
        private ?string $tenantId = null,
        private ?string $channel = null,
        private array $attributes = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            requestId: 'authz-' . bin2hex(random_bytes(8)),
            tenantId: null,
            channel: null,
            attributes: [],
        );
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function channel(): ?string
    {
        return $this->channel;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            requestId: $this->requestId,
            tenantId: $this->tenantId,
            channel: $this->channel,
            attributes: $attributes,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function mergeAttributes(array $attributes): self
    {
        return $this->withAttributes(array_replace($this->attributes, $attributes));
    }
}
