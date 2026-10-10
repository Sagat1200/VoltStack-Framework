<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use RuntimeException;

/**
 * Stateless shared helper used by all ORM plan compilers.
 *
 * Encapsulates the common low-level primitives:
 *  - association path normalization (with validation against metadata)
 *  - association path grouping by root hop
 *  - root+tail splitting of dotted paths
 *  - joined result key convention used during SELECT AS compilation
 *
 * This class is intentionally stateless and NOT registered in the container.
 * Planners either call static helpers or instantiate it directly; metadata is
 * always passed explicitly so the helper remains singleton-safe.
 */
final readonly class PlanningMetadataHelper
{
    /**
     * Normalize an association path like " comments.post " into its canonical
     * dotted form using `EntityAssociationMetadata->name` for every segment.
     *
     * Returns an empty string when the input trims to nothing.
     * Throws RuntimeException if any segment does not resolve to a known association.
     */
    public function normalizeAssociationPath(
        EntityMetadata $rootMetadata,
        string $associationPath,
        EntityMetadataRegistry $metadataRegistry,
    ): string {
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
        $currentMetadata = $rootMetadata;

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
            $currentMetadata = $metadataRegistry->for($association->targetEntity);
        }

        return implode('.', $resolved);
    }

    /**
     * Group a list of association paths by their root hop, then sort both keys
     * and tail lists for deterministic output.
     *
     * @param list<string> $paths
     * @return array<string, list<string>>
     */
    public function groupAssociationPaths(EntityMetadata $metadata, array $paths): array
    {
        $grouped = [];

        foreach ($paths as $path) {
            $normalized = trim($path);
            if ($normalized === '') {
                continue;
            }

            [$rootAssociation, $tailPath] = $this->splitAssociationPath($normalized);
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
     * Split a dotted association path such as "comments.post.votes" into:
     *   [0] => "comments" (root hop)
     *   [1] => "post.votes" (optional nested tail, or null when only one segment)
     *
     * @return array{0:string,1:?string}
     */
    public function splitAssociationPath(string $associationPath): array
    {
        $segments = explode('.', $associationPath, 2);

        return [$segments[0], $segments[1] ?? null];
    }

    /**
     * Build the canonical result-set key used to alias joined-entity columns
     * so they do not collide with root columns or other joined associations.
     *
     * Convention: `__orm_join_<associationName>_<column>` with any character
     * outside [A-Za-z0-9_] replaced by "_".
     */
    public function joinedResultKey(string $associationName, string $column): string
    {
        return '__orm_join_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $associationName . '_' . $column);
    }
}
