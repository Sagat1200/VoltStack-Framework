<?php

declare(strict_types=1);

namespace Quantum\Bootstrap;

use VoltStack\Framework\ServiceProvider;

final readonly class ApplicationPlan
{
    /**
     * @param list<class-string<ServiceProvider>> $providers
     * @param list<string> $discoveryAllowPackages
     * @param list<string> $discoveryDenyPackages
     */
    public function __construct(
        private string $basePath,
        private string $configDirectory,
        private array $providers = [],
        private ?string $environment = null,
        private ?string $profile = null,
        private ?string $artifactDirectory = null,
        private bool $discoveryEnabled = true,
        private array $discoveryAllowPackages = [],
        private array $discoveryDenyPackages = [],
    ) {
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function configDirectory(): string
    {
        return $this->configDirectory;
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function environment(): ?string
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

    public function discoveryEnabled(): bool
    {
        return $this->discoveryEnabled;
    }

    /**
     * @return list<string>
     */
    public function discoveryAllowPackages(): array
    {
        return $this->discoveryAllowPackages;
    }

    /**
     * @return list<string>
     */
    public function discoveryDenyPackages(): array
    {
        return $this->discoveryDenyPackages;
    }

    /**
     * @return array{
     *     base_path: string,
     *     config_directory: string,
     *     providers: list<class-string<ServiceProvider>>,
     *     environment: ?string,
     *     profile: ?string,
     *     artifact_directory: ?string,
     *     discovery: array{
     *         enabled: bool,
     *         allow_packages: list<string>,
     *         deny_packages: list<string>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'base_path' => $this->basePath,
            'config_directory' => $this->configDirectory,
            'providers' => $this->providers,
            'environment' => $this->environment,
            'profile' => $this->profile,
            'artifact_directory' => $this->artifactDirectory,
            'discovery' => [
                'enabled' => $this->discoveryEnabled,
                'allow_packages' => $this->discoveryAllowPackages,
                'deny_packages' => $this->discoveryDenyPackages,
            ],
        ];
    }
}
