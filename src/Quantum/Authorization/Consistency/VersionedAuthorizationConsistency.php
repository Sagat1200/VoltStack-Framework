<?php

declare(strict_types=1);

namespace Quantum\Authorization\Consistency;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Cache\Contracts\VersionAuthorityInterface;

final readonly class VersionedAuthorizationConsistency implements AuthorizationConsistencyInterface
{
    public function __construct(
        private VersionAuthorityInterface $versions,
        private string $namespace = 'authorization.consistency',
    ) {}

    public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
    {
        return $this->compositeVersion('authority', $principalId, $scope);
    }

    public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
    {
        return $this->compositeVersion('relationships', $principalId, $scope);
    }

    public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null): array
    {
        return $this->invalidateDomain('authority', $principalId, $scope);
    }

    public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null): array
    {
        return $this->invalidateDomain('relationships', $principalId, $scope);
    }

    private function compositeVersion(string $domain, string $principalId, Scope|string $scope): string
    {
        $principalId = trim($principalId);
        $scopeValue = $this->normalizeScope($scope);

        return implode('|', [
            $this->versions->currentVersion($this->globalScope($domain)),
            $this->versions->currentVersion($this->principalScope($domain, $principalId)),
            $this->versions->currentVersion($this->scopeScope($domain, $scopeValue)),
            $this->versions->currentVersion($this->principalScopeScope($domain, $principalId, $scopeValue)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function invalidateDomain(string $domain, ?string $principalId, Scope|string|null $scope): array
    {
        $normalizedPrincipal = is_string($principalId) ? trim($principalId) : '';
        $normalizedScope = $scope === null ? null : $this->normalizeScope($scope);

        if ($normalizedPrincipal === '' && $normalizedScope === null) {
            return [
                'global' => $this->versions->bump($this->globalScope($domain)),
            ];
        }

        $result = [];

        if ($normalizedPrincipal !== '') {
            $result['principal'] = $this->versions->bump($this->principalScope($domain, $normalizedPrincipal));
        }

        if ($normalizedScope !== null) {
            $result['scope'] = $this->versions->bump($this->scopeScope($domain, $normalizedScope));
        }

        if ($normalizedPrincipal !== '' && $normalizedScope !== null) {
            $result['principal_scope'] = $this->versions->bump($this->principalScopeScope($domain, $normalizedPrincipal, $normalizedScope));
        }

        return $result;
    }

    private function normalizeScope(Scope|string $scope): string
    {
        return (string) ($scope instanceof Scope ? $scope : new Scope($scope));
    }

    private function globalScope(string $domain): string
    {
        return $this->namespace . '.' . $domain . '.global';
    }

    private function principalScope(string $domain, string $principalId): string
    {
        return $this->namespace . '.' . $domain . '.principal.' . sha1($principalId);
    }

    private function scopeScope(string $domain, string $scope): string
    {
        return $this->namespace . '.' . $domain . '.scope.' . sha1($scope);
    }

    private function principalScopeScope(string $domain, string $principalId, string $scope): string
    {
        return $this->namespace . '.' . $domain . '.principal_scope.' . sha1($principalId . '|' . $scope);
    }
}
