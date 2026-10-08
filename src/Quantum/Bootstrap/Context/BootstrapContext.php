<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Context;

use InvalidArgumentException;

final readonly class BootstrapContext
{
    public function __construct(
        private string $environment,
        private string $artifactPolicy = 'prefer-artifacts',
        private string $executionMode = 'single',
        private ?string $profile = null,
    ) {
    }

    public static function forConsole(
        string $environment,
        string $artifactPolicy = 'prefer-artifacts',
        string $executionMode = 'single',
        ?string $profile = null,
    ): self {
        $environment = trim($environment);
        $artifactPolicy = trim($artifactPolicy);
        $executionMode = trim($executionMode);

        if ($environment === '') {
            throw new InvalidArgumentException('BootstrapContext environment cannot be empty.');
        }

        if ($artifactPolicy === '') {
            throw new InvalidArgumentException('BootstrapContext artifact policy cannot be empty.');
        }

        if ($executionMode === '') {
            throw new InvalidArgumentException('BootstrapContext execution mode cannot be empty.');
        }

        return new self(
            environment: $environment,
            artifactPolicy: $artifactPolicy,
            executionMode: $executionMode,
            profile: self::normalizeOptional($profile),
        );
    }

    public static function forRuntime(
        string $environment,
        bool $persistent = true,
        string $artifactPolicy = 'prefer-artifacts',
        ?string $profile = null,
    ): self {
        return self::forConsole(
            environment: $environment,
            artifactPolicy: $artifactPolicy,
            executionMode: $persistent ? 'worker' : 'single',
            profile: $profile,
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function artifactPolicy(): string
    {
        return $this->artifactPolicy;
    }

    public function executionMode(): string
    {
        return $this->executionMode;
    }

    public function profile(): ?string
    {
        return $this->profile;
    }

    private static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
