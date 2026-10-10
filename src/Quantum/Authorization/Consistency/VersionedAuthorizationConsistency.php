<?php

declare(strict_types=1);

namespace Quantum\Authorization\Consistency;

use DateTimeImmutable;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\Contracts\VersionAuthorityInterface;
use Quantum\Cache\FileVersionAuthority;

final class VersionedAuthorizationConsistency implements AuthorizationConsistencyInterface
{
    private VersionAuthorityInterface $versions;

    private string $namespace;

    /**
     * @var array<string, int>
     */
    private array $bumpCounters;

    /**
     * @var array<string, string>
     */
    private array $bumpReasons;

    /**
     * @var array<string, string>
     */
    private array $bumpTimestamps;

    private string $lastBumpTimestamp;

    public function __construct(
        VersionAuthorityInterface $versions,
        string $namespace = 'authorization.consistency',
    ) {
        $this->versions = $versions;
        $this->namespace = $namespace;
        $this->bumpCounters = [];
        $this->bumpReasons = [];
        $this->bumpTimestamps = [];
        $this->lastBumpTimestamp = '';
    }

    public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
    {
        return $this->compositeVersion('authority', $principalId, $scope);
    }

    public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
    {
        return $this->compositeVersion('relationships', $principalId, $scope);
    }

    public function invalidateAuthority(
        ?string $principalId = null,
        Scope|string|null $scope = null,
        ?string $reason = null,
    ): array {
        return $this->invalidateDomain('authority', $principalId, $scope, $reason);
    }

    public function invalidateRelationships(
        ?string $principalId = null,
        Scope|string|null $scope = null,
        ?string $reason = null,
    ): array {
        return $this->invalidateDomain('relationships', $principalId, $scope, $reason);
    }

    public function driver(): string
    {
        return $this->versions::class;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return array<string, string>
     */
    public function describeAuthority(?string $principalId = null, Scope|string|null $scope = null): array
    {
        return $this->describeDomain('authority', $principalId, $scope);
    }

    /**
     * @return array<string, string>
     */
    public function describeRelationships(?string $principalId = null, Scope|string|null $scope = null): array
    {
        return $this->describeDomain('relationships', $principalId, $scope);
    }

    /**
     * @return array<string, string>
     */
    public function lastBumpTimestamps(): array
    {
        return $this->bumpTimestamps;
    }

    /**
     * @return array<string, int>
     */
    public function bumpCounters(): array
    {
        return $this->bumpCounters;
    }

    /**
     * @return array<string, string>
     */
    public function bumpReasons(): array
    {
        return $this->bumpReasons;
    }

    public function lastBumpAt(): ?string
    {
        return $this->lastBumpTimestamp === '' ? null : $this->lastBumpTimestamp;
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        return [
            'implementation' => self::class,
            'namespace' => $this->namespace,
            'version_authority' => $this->versions::class,
            'version_authority_info' => $this->describeVersionAuthority(),
            'last_bump_at' => $this->lastBumpAt(),
            'bump_counters_by_segment' => $this->bumpCounters,
            'last_bump_reasons_by_segment' => $this->bumpReasons,
            'last_bump_timestamps_by_segment' => $this->bumpTimestamps,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function describeVersionAuthority(): array
    {
        if ($this->versions instanceof FileVersionAuthority) {
            return [
                'kind' => 'file',
                'storage_path' => $this->versions->storagePath(),
            ];
        }

        if ($this->versions instanceof CacheVersionAuthority) {
            return [
                'kind' => 'cache',
                'store' => $this->versions->store()::class,
                'prefix' => $this->versions->keyPrefix(),
            ];
        }

        return [
            'kind' => 'generic',
        ];
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
    private function invalidateDomain(
        string $domain,
        ?string $principalId,
        Scope|string|null $scope,
        ?string $reason,
    ): array {
        $normalizedPrincipal = is_string($principalId) ? trim($principalId) : '';
        $normalizedScope = $scope === null ? null : $this->normalizeScope($scope);
        $normalizedReason = is_string($reason) ? trim($reason) : '';

        if ($normalizedPrincipal === '' && $normalizedScope === null) {
            return [
                'global' => $this->bumpSegment($domain . '.global', $this->globalScope($domain), $normalizedReason),
            ];
        }

        $result = [];

        if ($normalizedPrincipal !== '') {
            $result['principal'] = $this->bumpSegment(
                $domain . '.principal',
                $this->principalScope($domain, $normalizedPrincipal),
                $normalizedReason,
            );
        }

        if ($normalizedScope !== null) {
            $result['scope'] = $this->bumpSegment(
                $domain . '.scope',
                $this->scopeScope($domain, $normalizedScope),
                $normalizedReason,
            );
        }

        if ($normalizedPrincipal !== '' && $normalizedScope !== null) {
            $result['principal_scope'] = $this->bumpSegment(
                $domain . '.principal_scope',
                $this->principalScopeScope($domain, $normalizedPrincipal, $normalizedScope),
                $normalizedReason,
            );
        }

        return $result;
    }

    private function bumpSegment(string $segmentKey, string $versionScope, string $reason): string
    {
        $version = $this->versions instanceof FileVersionAuthority || $this->versions instanceof CacheVersionAuthority
            ? $this->versions->bump($versionScope, $reason !== '' ? $reason : null)
            : $this->versions->bump($versionScope);

        $envelope = $this->readSharedEnvelope($versionScope);
        $timestamp = $envelope['last_bump_at'] !== '' ? $envelope['last_bump_at'] : (new DateTimeImmutable())->format('c');

        $this->bumpCounters[$segmentKey] = $envelope['bump_counter'] > 0
            ? $envelope['bump_counter']
            : ($this->bumpCounters[$segmentKey] ?? 0) + 1;
        $this->bumpTimestamps[$segmentKey] = $timestamp;

        if ($envelope['last_reason'] !== '') {
            $this->bumpReasons[$segmentKey] = $envelope['last_reason'];
        } elseif ($reason !== '') {
            $this->bumpReasons[$segmentKey] = $reason;
        } elseif (! isset($this->bumpReasons[$segmentKey])) {
            $this->bumpReasons[$segmentKey] = '';
        }

        $this->lastBumpTimestamp = $timestamp;

        return $version;
    }

    /**
     * Reads the shared audit envelope from the underlying version authority
     * when the concrete implementation exposes it; otherwise returns empty
     * defaults so in-process counters still drive the projection.
     *
     * @return array{bump_counter:int<0,max>,last_reason:string,last_bump_at:string}
     */
    private function readSharedEnvelope(string $versionScope): array
    {
        if ($this->versions instanceof FileVersionAuthority || $this->versions instanceof CacheVersionAuthority) {
            $envelope = $this->versions->readEnvelope($versionScope);

            return [
                'bump_counter' => $envelope['bump_counter'],
                'last_reason' => $envelope['last_reason'],
                'last_bump_at' => $envelope['last_bump_at'],
            ];
        }

        return [
            'bump_counter' => 0,
            'last_reason' => '',
            'last_bump_at' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function describeDomain(string $domain, ?string $principalId, Scope|string|null $scope): array
    {
        $normalizedPrincipal = is_string($principalId) ? trim($principalId) : '';
        $normalizedScope = $scope === null ? null : $this->normalizeScope($scope);

        $result = [
            'global' => $this->versions->currentVersion($this->globalScope($domain)),
        ];

        if ($normalizedPrincipal !== '') {
            $result['principal'] = $this->versions->currentVersion($this->principalScope($domain, $normalizedPrincipal));
        }

        if ($normalizedScope !== null) {
            $result['scope'] = $this->versions->currentVersion($this->scopeScope($domain, $normalizedScope));
        }

        if ($normalizedPrincipal !== '' && $normalizedScope !== null) {
            $result['principal_scope'] = $this->versions->currentVersion(
                $this->principalScopeScope($domain, $normalizedPrincipal, $normalizedScope),
            );
            $result['composite'] = implode('|', [
                $result['global'],
                $result['principal'],
                $result['scope'],
                $result['principal_scope'],
            ]);
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
