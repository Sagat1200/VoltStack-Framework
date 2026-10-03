<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Context;

use InvalidArgumentException;

final readonly class BuildContext
{
    public function __construct(
        private string $environment,
        private ?string $profile = null,
        private ?string $artifactDirectory = null,
        private ?string $releaseId = null,
    ) {
    }

    public static function forEnvironment(
        string $environment,
        ?string $profile = null,
        ?string $artifactDirectory = null,
        ?string $releaseId = null,
    ): self {
        $environment = trim($environment);

        if ($environment === '') {
            throw new InvalidArgumentException('BuildContext environment cannot be empty.');
        }

        return new self(
            environment: $environment,
            profile: self::normalizeOptional($profile),
            artifactDirectory: self::normalizeOptionalPath($artifactDirectory),
            releaseId: self::normalizeOptional($releaseId),
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function profile(): ?string
    {
        return $this->profile;
    }

    public function artifactDirectory(): ?string
    {
        return $this->artifactDirectory;
    }

    public function releaseId(): ?string
    {
        return $this->releaseId;
    }

    private static function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeOptionalPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '\\/');
    }
}
