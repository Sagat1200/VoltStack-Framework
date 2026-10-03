<?php

declare(strict_types=1);

namespace Quantum\Bootstrap;

use InvalidArgumentException;
use LogicException;
use VoltStack\Framework\ServiceProvider;

final class ApplicationBuilder
{
    /**
     * @var list<class-string<ServiceProvider>>
     */
    private array $providers = [];

    private string $configDirectory;

    private ?string $environment = null;

    private ?string $profile = null;

    private ?string $artifactDirectory = null;

    private bool $discoveryEnabled = true;

    /**
     * @var list<string>
     */
    private array $discoveryAllowPackages = [];

    /**
     * @var list<string>
     */
    private array $discoveryDenyPackages = [];

    private bool $sealed = false;

    private ?ApplicationPlan $builtPlan = null;

    private function __construct(private readonly string $basePath)
    {
        $this->configDirectory = self::normalizePath($basePath . DIRECTORY_SEPARATOR . 'config');
    }

    public static function create(string $basePath): self
    {
        return new self(self::normalizePath($basePath));
    }

    /**
     * @param list<class-string<ServiceProvider>> $providers
     */
    public function withProviders(array $providers): self
    {
        $this->assertNotSealed();

        foreach ($providers as $provider) {
            $provider = $this->normalizeProvider($provider);

            if (! in_array($provider, $this->providers, true)) {
                $this->providers[] = $provider;
            }
        }

        return $this;
    }

    public function withConfigDirectory(string $configDirectory): self
    {
        $this->assertNotSealed();
        $this->configDirectory = self::normalizePath($configDirectory);

        return $this;
    }

    public function withEnvironment(string $environment): self
    {
        $this->assertNotSealed();
        $this->environment = $this->normalizeNonEmptyValue($environment, 'environment');

        return $this;
    }

    public function withProfile(string $profile): self
    {
        $this->assertNotSealed();
        $this->profile = $this->normalizeNonEmptyValue($profile, 'profile');

        return $this;
    }

    public function withArtifactDirectory(string $artifactDirectory): self
    {
        $this->assertNotSealed();
        $this->artifactDirectory = self::normalizePath($artifactDirectory);

        return $this;
    }

    /**
     * @param list<string> $allowPackages
     */
    public function withDiscovery(bool $enabled = true, array $allowPackages = []): self
    {
        $this->assertNotSealed();
        $this->discoveryEnabled = $enabled;
        $this->discoveryAllowPackages = $this->normalizePackageList($allowPackages);

        return $this;
    }

    /**
     * @param list<string> $packages
     */
    public function withoutDiscovery(array $packages): self
    {
        $this->assertNotSealed();

        foreach ($this->normalizePackageList($packages) as $package) {
            if (! in_array($package, $this->discoveryDenyPackages, true)) {
                $this->discoveryDenyPackages[] = $package;
            }
        }

        return $this;
    }

    public function build(): ApplicationPlan
    {
        if ($this->builtPlan !== null) {
            return $this->builtPlan;
        }

        $this->validate();
        $this->sealed = true;

        return $this->builtPlan = new ApplicationPlan(
            basePath: $this->basePath,
            configDirectory: $this->configDirectory,
            providers: $this->providers,
            environment: $this->environment,
            profile: $this->profile,
            artifactDirectory: $this->artifactDirectory,
            discoveryEnabled: $this->discoveryEnabled,
            discoveryAllowPackages: $this->discoveryAllowPackages,
            discoveryDenyPackages: $this->discoveryDenyPackages,
        );
    }

    public function sealed(): bool
    {
        return $this->sealed;
    }

    private function validate(): void
    {
        if ($this->basePath === '' || ! is_dir($this->basePath)) {
            throw new InvalidArgumentException(sprintf('ApplicationBuilder base path [%s] does not exist.', $this->basePath));
        }

        if ($this->configDirectory === '') {
            throw new InvalidArgumentException('ApplicationBuilder config directory cannot be empty.');
        }
    }

    private function assertNotSealed(): void
    {
        if ($this->sealed) {
            throw new LogicException('ApplicationBuilder is sealed after build(). Create a new builder to change the plan.');
        }
    }

    /**
     * @param class-string<ServiceProvider> $provider
     * @return class-string<ServiceProvider>
     */
    private function normalizeProvider(string $provider): string
    {
        $provider = ltrim(trim($provider), '\\');

        if ($provider === '') {
            throw new InvalidArgumentException('Provider class name cannot be empty.');
        }

        if (class_exists($provider) && ! is_subclass_of($provider, ServiceProvider::class)) {
            throw new InvalidArgumentException(sprintf(
                'Provider [%s] must extend [%s].',
                $provider,
                ServiceProvider::class,
            ));
        }

        /** @var class-string<ServiceProvider> $provider */
        return $provider;
    }

    /**
     * @param list<string> $packages
     * @return list<string>
     */
    private function normalizePackageList(array $packages): array
    {
        $normalized = [];

        foreach ($packages as $package) {
            $package = trim($package);

            if ($package === '' || in_array($package, $normalized, true)) {
                continue;
            }

            $normalized[] = $package;
        }

        return $normalized;
    }

    private function normalizeNonEmptyValue(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('ApplicationBuilder %s cannot be empty.', $label));
        }

        return $value;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), '\\/');
    }
}
