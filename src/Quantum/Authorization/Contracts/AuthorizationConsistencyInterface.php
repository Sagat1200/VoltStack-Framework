<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Scope;

interface AuthorizationConsistencyInterface
{
    public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string;

    public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string;

    /**
     * @return array<string, string>
     */
    public function invalidateAuthority(
        ?string $principalId = null,
        Scope|string|null $scope = null,
        ?string $reason = null,
    ): array;

    /**
     * @return array<string, string>
     */
    public function invalidateRelationships(
        ?string $principalId = null,
        Scope|string|null $scope = null,
        ?string $reason = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array;

    /**
     * Proyeccion agregada de metricas de flush (bumps) por segmento.
     *
     * @return array{bump_counters_by_segment:array<string,int>,last_bump_reasons_by_segment:array<string,string>,last_bump_timestamps_by_segment:array<string,string>,total_bumps:int}
     */
    public function flushMetrics(): array;
}
