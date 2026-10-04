<?php

declare(strict_types=1);

namespace Quantum\Config\Scope;

use Quantum\Config\ConfigRepository;
use Quantum\Config\ConfigSnapshot;
use RuntimeException;
use VoltStack\Framework\Application;

final class ConfigurationScopeRegistry
{
    /**
     * @var array<string, ConfigurationScope>
     */
    private array $scopes = [];

    public function __construct(
        private readonly Application $app,
        private readonly ConfigRepository $repository,
    ) {
    }

    public function current(): ?ConfigurationScope
    {
        if (! $this->app->hasActiveScope()) {
            return null;
        }

        $kind = $this->app->currentScopeKind();

        if (in_array($kind, ['root', 'worker'], true)) {
            return null;
        }

        $id = $this->app->currentScopeId();

        if (! isset($this->scopes[$id])) {
            $this->scopes[$id] = new ConfigurationScope(
                id: $id,
                kind: $kind,
                parentId: $this->app->currentScopeParentId(),
                baseSnapshot: $this->resolveBaseSnapshot($this->app->currentScopeParentId()),
            );
        }

        return $this->scopes[$id];
    }

    public function currentOrFail(): ConfigurationScope
    {
        $scope = $this->current();

        if ($scope !== null) {
            return $scope;
        }

        throw new RuntimeException('Configuration overrides require an active request, command, job, or tenant scope.');
    }

    public function endScope(string $scopeId): void
    {
        unset($this->scopes[$scopeId]);
    }

    public function clearCurrent(): void
    {
        $this->current()?->clearOverrides();
    }

    public function flush(): void
    {
        $this->scopes = [];
    }

    private function resolveBaseSnapshot(?string $parentScopeId): ConfigSnapshot
    {
        if ($parentScopeId !== null && isset($this->scopes[$parentScopeId])) {
            return $this->scopes[$parentScopeId]->snapshot();
        }

        return $this->repository->snapshot();
    }
}
