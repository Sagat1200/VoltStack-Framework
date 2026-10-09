<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

final readonly class RuntimeConfiguration
{
    public function __construct(
        private string $driver = 'frankenphp',
        private int $maxRequests = 1,
        private bool $drainOnTerminate = true,
        private mixed $requestSource = null,
        private ?string $environment = null,
        private ?string $profile = null,
    ) {
    }

    public static function frankenphp(
        int $maxRequests = 1,
        mixed $requestSource = null,
        ?string $environment = null,
        ?string $profile = null,
    ): self {
        return new self(
            driver: 'frankenphp',
            maxRequests: $maxRequests,
            drainOnTerminate: true,
            requestSource: $requestSource,
            environment: $environment,
            profile: $profile,
        );
    }

    public static function roadrunner(
        int $maxRequests = 1,
        mixed $requestSource = null,
        ?string $environment = null,
        ?string $profile = null,
    ): self {
        return new self(
            driver: 'roadrunner',
            maxRequests: $maxRequests,
            drainOnTerminate: true,
            requestSource: $requestSource,
            environment: $environment,
            profile: $profile,
        );
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function maxRequests(): int
    {
        return max(1, $this->maxRequests);
    }

    public function drainOnTerminate(): bool
    {
        return $this->drainOnTerminate;
    }

    public function requestSource(): mixed
    {
        return $this->requestSource;
    }

    public function environment(): ?string
    {
        return $this->environment;
    }

    public function profile(): ?string
    {
        return $this->profile;
    }
}
