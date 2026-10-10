<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;

final readonly class AssociationFetchPlanCompiler
{
    private PlanningMetadataHelper $helper;

    public function __construct(
        private EntityMetadataRegistry $metadataRegistry,
    ) {
        $this->helper = new PlanningMetadataHelper();
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
            [$rootAssociation] = $this->helper->splitAssociationPath($path);
            if (in_array($rootAssociation, $joinedRootAssociations, true)) {
                continue;
            }

            if (! in_array($path, $preloadPaths, true)) {
                $preloadPaths[] = $path;
            }
        }

        $joinedNestedPaths = [];
        foreach ($normalizedExplicitPaths as $path) {
            [$rootAssociation, $tailPath] = $this->helper->splitAssociationPath($path);
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
        return $this->helper->normalizeAssociationPath($metadata, $associationPath, $this->metadataRegistry);
    }

    /**
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public function groupPaths(EntityMetadata $metadata, array $paths): array
    {
        return $this->helper->groupAssociationPaths($metadata, $paths);
    }
}
