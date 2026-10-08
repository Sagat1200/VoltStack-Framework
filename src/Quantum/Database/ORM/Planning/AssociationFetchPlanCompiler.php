<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use RuntimeException;

final readonly class AssociationFetchPlanCompiler
{
    public function __construct(
        private EntityMetadataRegistry $metadataRegistry,
    ) {
    }

    /**
     * @param list<string> $explicitPaths
     * @param list<string> $joinedRootAssociations
     */
    public function compile(EntityMetadata $metadata, array $explicitPaths, array $joinedRootAssociations = []): AssociationFetchPlan
    {
        $normalizedExplicitPaths = [];

        foreach ($explicitPaths as $path) {
            $normalized = $this->normalizePath($metadata, $path);
            if ($normalized === '' || in_array($normalized, $normalizedExplicitPaths, true)) {
                continue;
            }

            $normalizedExplicitPaths[] = $normalized;
        }

        sort($joinedRootAssociations);

        $preloadPaths = [];
        foreach (array_merge($normalizedExplicitPaths, $metadata->eagerAssociationNames()) as $path) {
            [$rootAssociation] = $this->splitPath($path);
            if (in_array($rootAssociation, $joinedRootAssociations, true)) {
                continue;
            }

            if (! in_array($path, $preloadPaths, true)) {
                $preloadPaths[] = $path;
            }
        }

        $joinedNestedPaths = [];
        foreach ($normalizedExplicitPaths as $path) {
            [$rootAssociation, $tailPath] = $this->splitPath($path);
            if ($tailPath === null || ! in_array($rootAssociation, $joinedRootAssociations, true)) {
                continue;
            }

            $joinedNestedPaths[$rootAssociation] ??= [];
            if (! in_array($tailPath, $joinedNestedPaths[$rootAssociation], true)) {
                $joinedNestedPaths[$rootAssociation][] = $tailPath;
            }
        }

        sort($preloadPaths);
        ksort($joinedNestedPaths);
        foreach ($joinedNestedPaths as &$tails) {
            sort($tails);
        }
        unset($tails);

        return new AssociationFetchPlan($preloadPaths, $joinedNestedPaths);
    }

    public function normalizePath(EntityMetadata $metadata, string $associationPath): string
    {
        $normalized = trim($associationPath);
        if ($normalized === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('.', $normalized),
            static fn(string $segment): bool => trim($segment) !== '',
        ));

        if ($segments === []) {
            return '';
        }

        $resolved = [];
        $currentMetadata = $metadata;

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (! $currentMetadata->hasAssociation($segment)) {
                throw new RuntimeException(sprintf(
                    'Cannot preload unknown association path [%s] from [%s] at segment [%s].',
                    $normalized,
                    $currentMetadata->className,
                    $segment,
                ));
            }

            $association = $currentMetadata->association($segment);
            $resolved[] = $association->name;
            $currentMetadata = $this->metadataRegistry->for($association->targetEntity);
        }

        return implode('.', $resolved);
    }

    /**
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public function groupPaths(EntityMetadata $metadata, array $paths): array
    {
        $grouped = [];

        foreach ($paths as $path) {
            $normalized = trim($path);
            if ($normalized === '') {
                continue;
            }

            [$rootAssociation, $tailPath] = $this->splitPath($normalized);
            if (! $metadata->hasAssociation($rootAssociation)) {
                throw new RuntimeException(sprintf(
                    'Cannot preload unknown association path [%s] on [%s].',
                    $normalized,
                    $metadata->className,
                ));
            }

            $grouped[$rootAssociation] ??= [];
            if ($tailPath === null || in_array($tailPath, $grouped[$rootAssociation], true)) {
                continue;
            }

            $grouped[$rootAssociation][] = $tailPath;
        }

        ksort($grouped);
        foreach ($grouped as &$tails) {
            sort($tails);
        }
        unset($tails);

        return $grouped;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function splitPath(string $associationPath): array
    {
        $segments = explode('.', $associationPath, 2);

        return [$segments[0], $segments[1] ?? null];
    }
}
