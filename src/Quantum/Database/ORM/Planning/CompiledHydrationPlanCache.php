<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use RuntimeException;

/**
 * Deterministic per-EntityManager cache for compiled ORM plans.
 *
 * Scope: this cache is always owned by one EntityManager instance. It MUST NOT
 * be used as a global / DI singleton because compiled plans are built against
 * joined-association maps and selections that are specific to a request scope.
 *
 * Cache keys are built from sorted, canonical inputs so two logically
 * identical compilations produce the same key regardless of input ordering.
 *
 * Exposed as a concrete mutable class (NOT readonly) because the whole point
 * of this object is to accumulate cache entries during a scope's lifetime.
 */
final class CompiledHydrationPlanCache
{
    /**
     * @var array<string, AssociationFetchPlan|EntityHydrationPlan|PartialHydrationPlan>
     */
    private array $entries = [];

    private int $hits = 0;
    private int $misses = 0;

    /**
     * @param list<string> $explicitPaths
     * @param list<string> $joinedRootAssociations
     */
    public function rememberFetchPlan(
        EntityMetadata $metadata,
        array $explicitPaths,
        array $joinedRootAssociations,
        callable $factory,
    ): AssociationFetchPlan {
        $key = $this->fetchPlanKey($metadata, $explicitPaths, $joinedRootAssociations);

        if (isset($this->entries[$key])) {
            $cached = $this->entries[$key];
            if (! $cached instanceof AssociationFetchPlan) {
                throw new RuntimeException(sprintf(
                    'CompiledHydrationPlanCache key collision for fetch plan: expected AssociationFetchPlan, got [%s].',
                    get_debug_type($cached),
                ));
            }

            $this->hits++;

            return $cached;
        }

        $this->misses++;
        $plan = $factory();
        if (! $plan instanceof AssociationFetchPlan) {
            throw new RuntimeException(sprintf(
                'CompiledHydrationPlanCache fetch-plan factory produced [%s] instead of AssociationFetchPlan.',
                get_debug_type($plan),
            ));
        }

        $this->entries[$key] = $plan;

        return $plan;
    }

    /**
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function rememberEntityHydrationPlan(
        EntityMetadata $metadata,
        string $rootAlias,
        array $joinedAssociations,
        callable $factory,
    ): EntityHydrationPlan {
        $key = $this->entityHydrationPlanKey($metadata, $rootAlias, $joinedAssociations);

        if (isset($this->entries[$key])) {
            $cached = $this->entries[$key];
            if (! $cached instanceof EntityHydrationPlan) {
                throw new RuntimeException(sprintf(
                    'CompiledHydrationPlanCache key collision for entity hydration plan: expected EntityHydrationPlan, got [%s].',
                    get_debug_type($cached),
                ));
            }

            $this->hits++;

            return $cached;
        }

        $this->misses++;
        $plan = $factory();
        if (! $plan instanceof EntityHydrationPlan) {
            throw new RuntimeException(sprintf(
                'CompiledHydrationPlanCache entity-hydration factory produced [%s] instead of EntityHydrationPlan.',
                get_debug_type($plan),
            ));
        }

        $this->entries[$key] = $plan;

        return $plan;
    }

    /**
     * @param list<array{name:string,column:string}> $rootSelections
     * @param array<string, list<array{name:string,column:string,resultKey:string}>> $joinedSelections
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function rememberPartialHydrationPlan(
        EntityMetadata $metadata,
        string $rootAlias,
        array $rootSelections,
        array $joinedSelections,
        array $joinedAssociations,
        callable $factory,
    ): PartialHydrationPlan {
        $key = $this->partialHydrationPlanKey($metadata, $rootAlias, $rootSelections, $joinedSelections, $joinedAssociations);

        if (isset($this->entries[$key])) {
            $cached = $this->entries[$key];
            if (! $cached instanceof PartialHydrationPlan) {
                throw new RuntimeException(sprintf(
                    'CompiledHydrationPlanCache key collision for partial hydration plan: expected PartialHydrationPlan, got [%s].',
                    get_debug_type($cached),
                ));
            }

            $this->hits++;

            return $cached;
        }

        $this->misses++;
        $plan = $factory();
        if (! $plan instanceof PartialHydrationPlan) {
            throw new RuntimeException(sprintf(
                'CompiledHydrationPlanCache partial-hydration factory produced [%s] instead of PartialHydrationPlan.',
                get_debug_type($plan),
            ));
        }

        $this->entries[$key] = $plan;

        return $plan;
    }

    /**
     * @return array{hits:int,misses:int,entries:int}
     */
    public function getStats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'entries' => count($this->entries),
        ];
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * @param list<string> $associationNames
     * @return array<string, list<string>>
     */
    public function rememberGroupedAssociationPaths(
        EntityMetadata $metadata,
        array $associationNames,
        callable $factory,
    ): array {
        $key = $this->groupedPathsKey($metadata, $associationNames);

        if (isset($this->entries[$key])) {
            $cached = $this->entries[$key];
            if (! is_array($cached)) {
                throw new RuntimeException(sprintf(
                    'CompiledHydrationPlanCache key collision for grouped association paths: expected array, got [%s].',
                    get_debug_type($cached),
                ));
            }

            $this->hits++;

            return $cached;
        }

        $this->misses++;
        $result = $factory();
        if (! is_array($result)) {
            throw new RuntimeException(sprintf(
                'CompiledHydrationPlanCache grouped-association-paths factory produced [%s] instead of array.',
                get_debug_type($result),
            ));
        }

        $this->entries[$key] = $result;

        return $result;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * @param list<string> $associationNames
     */
    public function groupedPathsKey(EntityMetadata $metadata, array $associationNames): string
    {
        $names = $associationNames;
        sort($names, SORT_STRING);

        return 'groupPaths:' . $metadata->className . ':' . md5(implode('|', $names));
    }

    /**
     * @param list<string> $explicitPaths
     * @param list<string> $joinedRootAssociations
     */
    public function fetchPlanKey(EntityMetadata $metadata, array $explicitPaths, array $joinedRootAssociations): string
    {
        $paths = $explicitPaths;
        sort($paths, SORT_STRING);
        $joined = $joinedRootAssociations;
        sort($joined, SORT_STRING);

        return 'fetch:' . $metadata->className . ':' . md5(implode('|', $paths)) . ':' . implode(',', $joined);
    }

    /**
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function entityHydrationPlanKey(
        EntityMetadata $metadata,
        string $rootAlias,
        array $joinedAssociations,
    ): string {
        $assocNames = array_keys($joinedAssociations);
        sort($assocNames, SORT_STRING);

        $mapParts = [];
        foreach ($assocNames as $name) {
            $join = $joinedAssociations[$name];
            $mapParts[] = $name . '=' . $join['alias'] . '|' . $join['type'] . '|' . $join['targetMetadata']->className;
        }

        return 'hydration:' . $metadata->className . ':' . $rootAlias . ':' . md5(implode(';', $mapParts));
    }

    /**
     * @param list<array{name:string,column:string}> $rootSelections
     * @param array<string, list<array{name:string,column:string,resultKey:string}>> $joinedSelections
     * @param array<string, array{alias:string,type:string,association:EntityAssociationMetadata,targetMetadata:EntityMetadata}> $joinedAssociations
     */
    public function partialHydrationPlanKey(
        EntityMetadata $metadata,
        string $rootAlias,
        array $rootSelections,
        array $joinedSelections,
        array $joinedAssociations,
    ): string {
        $rootParts = [];
        foreach ($rootSelections as $s) {
            $rootParts[] = $s['name'] . '=' . $s['column'];
        }
        sort($rootParts, SORT_STRING);

        $joinedAssocNames = array_keys($joinedSelections);
        sort($joinedAssocNames, SORT_STRING);
        $joinedParts = [];
        foreach ($joinedAssocNames as $name) {
            $selections = $joinedSelections[$name];
            $selParts = [];
            foreach ($selections as $s) {
                $selParts[] = $s['name'] . '=' . $s['column'] . '=' . $s['resultKey'];
            }
            sort($selParts, SORT_STRING);
            $joinedParts[] = $name . ':' . implode('|', $selParts);
        }

        $underlyingAssocNames = array_keys($joinedAssociations);
        sort($underlyingAssocNames, SORT_STRING);

        return 'partial:'
            . $metadata->className . ':'
            . $rootAlias . ':'
            . md5(implode(';', $rootParts)) . ':'
            . md5(implode(';;', $joinedParts)) . ':'
            . implode(',', $underlyingAssocNames);
    }
}
