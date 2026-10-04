<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

use Quantum\Config\ConfigRepository;
use Quantum\Config\ConfigSnapshot;
use Quantum\Config\Scope\ConfigurationScopeRegistry;

final class ConfigBridge
{
    private ConfigBridgeSnapshot $snapshot;

    private ?ConfigurationScopeRegistry $scopeRegistry = null;

    public function __construct(
        private readonly ConfigAccessRegistry $registry,
        ?ConfigBridgeSnapshot $snapshot = null,
    ) {
        $snapshot ??= new ConfigBridgeSnapshot(new ConfigSnapshot([]), [], []);
        $this->snapshot = $snapshot;
    }

    public function sync(ConfigRepository $repository): ConfigBridgeSnapshot
    {
        $source = $repository->snapshot();
        $staticPaths = [];
        $scopedPaths = [];

        foreach ($this->registry->all() as $path => $rule) {
            $bucket = $rule->mode() === ConfigAccessMode::Scoped ? $scopedPaths : $staticPaths;

            if (! $source->has($path)) {
                if ($rule->mode() === ConfigAccessMode::Scoped) {
                    $scopedPaths = $bucket;
                } else {
                    $staticPaths = $bucket;
                }

                continue;
            }

            $bucket[$path] = $source->get($path);

            if ($rule->mode() === ConfigAccessMode::Scoped) {
                $scopedPaths = $bucket;
                continue;
            }

            $staticPaths = $bucket;
        }

        return $this->snapshot = new ConfigBridgeSnapshot(
            source: $source,
            staticPaths: $staticPaths,
            scopedPaths: $scopedPaths,
        );
    }

    public function snapshot(): ConfigBridgeSnapshot
    {
        return $this->snapshot;
    }

    public function useScopeRegistry(ConfigurationScopeRegistry $scopeRegistry): void
    {
        $this->scopeRegistry = $scopeRegistry;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $mode = $this->registry->modeFor($path);
        $selected = $mode === ConfigAccessMode::Scoped
            ? $this->snapshot->scopedPaths()
            : $this->snapshot->staticPaths();

        if (array_key_exists($path, $selected)) {
            return $selected[$path];
        }

        return $this->snapshot->source()->get($path, $default);
    }

    public function static(string $path, mixed $default = null): mixed
    {
        $selected = $this->snapshot->staticPaths();

        if (array_key_exists($path, $selected)) {
            return $selected[$path];
        }

        return $this->snapshot->source()->get($path, $default);
    }

    public function scoped(string $path, mixed $default = null): mixed
    {
        $scope = $this->scopeRegistry?->current();

        if ($scope !== null) {
            return $scope->get($path, $default);
        }

        $selected = $this->snapshot->scopedPaths();

        if (array_key_exists($path, $selected)) {
            return $selected[$path];
        }

        return $this->snapshot->source()->get($path, $default);
    }
}
