<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Graph;

use Quantum\Bootstrap\Discovery\ProviderMetadata;
use Quantum\Bootstrap\Discovery\ProviderMetadataResolver;
use Quantum\Bootstrap\Exception\DependencyCycleException;
use Quantum\Bootstrap\Exception\DuplicateProviderIdException;
use Quantum\Bootstrap\Exception\MissingProviderDependencyException;
use Quantum\Bootstrap\Exception\ProviderConflictException;
use VoltStack\Framework\ServiceProvider;

final class ProviderDependencySorter
{
    public function __construct(
        private readonly ProviderMetadataResolver $resolver = new ProviderMetadataResolver(),
    ) {
    }

    /**
     * @param list<class-string<ServiceProvider>> $providerClasses
     * @return list<class-string<ServiceProvider>>
     */
    public function sort(array $providerClasses, ?string $environment = null, ?string $profile = null): array
    {
        $metadataById = [];
        $classById = [];

        foreach ($providerClasses as $providerClass) {
            $metadata = $this->resolver->resolve($providerClass);

            if (! $metadata->appliesTo($environment, $profile)) {
                continue;
            }

            if (isset($metadataById[$metadata->id()])) {
                throw DuplicateProviderIdException::forId($metadata->id());
            }

            $metadataById[$metadata->id()] = $metadata;
            $classById[$metadata->id()] = $providerClass;
        }

        foreach ($metadataById as $metadata) {
            foreach ($metadata->requires() as $requiredId) {
                if (! isset($metadataById[$requiredId])) {
                    throw MissingProviderDependencyException::forRequirement($metadata->id(), $requiredId);
                }
            }

            foreach ($metadata->conflicts() as $conflictingId) {
                if (isset($metadataById[$conflictingId])) {
                    throw ProviderConflictException::between($metadata->id(), $conflictingId);
                }
            }
        }

        $edges = [];
        $indegree = [];

        foreach (array_keys($metadataById) as $providerId) {
            $edges[$providerId] = [];
            $indegree[$providerId] = 0;
        }

        foreach ($metadataById as $metadata) {
            foreach ($metadata->requires() as $requiredId) {
                $this->addEdge($edges, $indegree, $requiredId, $metadata->id());
            }

            foreach ($metadata->after() as $dependencyId) {
                if (isset($metadataById[$dependencyId])) {
                    $this->addEdge($edges, $indegree, $dependencyId, $metadata->id());
                }
            }

            foreach ($metadata->before() as $targetId) {
                if (isset($metadataById[$targetId])) {
                    $this->addEdge($edges, $indegree, $metadata->id(), $targetId);
                }
            }
        }

        $queue = [];
        foreach ($indegree as $providerId => $degree) {
            if ($degree === 0) {
                $queue[] = $providerId;
            }
        }

        $orderedIds = [];

        while ($queue !== []) {
            usort($queue, fn (string $left, string $right): int => $this->compare(
                $metadataById[$left],
                $metadataById[$right],
            ));

            $currentId = array_shift($queue);
            if ($currentId === null) {
                break;
            }

            $orderedIds[] = $currentId;

            foreach (array_keys($edges[$currentId]) as $nextId) {
                $indegree[$nextId]--;
                if ($indegree[$nextId] === 0) {
                    $queue[] = $nextId;
                }
            }
        }

        if (count($orderedIds) !== count($metadataById)) {
            $remaining = [];
            foreach ($indegree as $providerId => $degree) {
                if ($degree > 0) {
                    $remaining[] = $providerId;
                }
            }

            sort($remaining, SORT_STRING);
            throw DependencyCycleException::forProviders($remaining);
        }

        return array_values(array_map(
            static fn (string $providerId): string => $classById[$providerId],
            $orderedIds,
        ));
    }

    /**
     * @param array<string, array<string, bool>> $edges
     * @param array<string, int> $indegree
     */
    private function addEdge(array &$edges, array &$indegree, string $from, string $to): void
    {
        if (isset($edges[$from][$to])) {
            return;
        }

        $edges[$from][$to] = true;
        $indegree[$to]++;
    }

    private function compare(ProviderMetadata $left, ProviderMetadata $right): int
    {
        if ($left->priority() !== $right->priority()) {
            return $right->priority() <=> $left->priority();
        }

        return strcmp($left->id(), $right->id());
    }
}
