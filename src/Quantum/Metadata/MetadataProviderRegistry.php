<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Contracts\MetadataProviderInterface;

final class MetadataProviderRegistry
{
    private array $providers = [];

    public function register(MetadataProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function all(): array
    {
        $indexed = [];

        foreach ($this->providers as $index => $provider) {
            $indexed[] = [
                'index' => $index,
                'provider' => $provider,
            ];
        }

        usort($indexed, static function (array $a, array $b): int {
            $priority = $b['provider']->priority() <=> $a['provider']->priority();

            if ($priority !== 0) {
                return $priority;
            }

            return $a['index'] <=> $b['index'];
        });

        $providers = [];

        foreach ($indexed as $row) {
            $providers[] = $row['provider'];
        }

        return $providers;
    }
}
