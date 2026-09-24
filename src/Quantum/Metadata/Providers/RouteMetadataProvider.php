<?php

declare(strict_types=1);

namespace Quantum\Metadata\Providers;

use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\RouteMatchSubject;

final class RouteMetadataProvider implements MetadataProviderInterface
{
    public function name(): string
    {
        return 'route';
    }

    public function priority(): int
    {
        return 850;
    }

    public function supports(MetadataRequest $request): bool
    {
        return $request->subject instanceof RouteMatchSubject;
    }

    public function provide(MetadataRequest $request): array
    {
        $subject = $request->subject;

        if (! $subject instanceof RouteMatchSubject) {
            return [];
        }

        $origin = new MetadataOrigin(
            provider: $this->name(),
            type: 'route',
            location: $subject->match()->route()->uri(),
        );

        $metadata = $subject->match()
            ->route()
            ->routeMetadata()
            ->all();

        $fragments = [];

        foreach ($metadata as $key => $value) {
            $fragments[] = new MetadataFragment(
                key: (string) $key,
                value: $value,
                origin: $origin,
                priority: $this->priority(),
            );

            if ($key !== 'authorization' || ! is_array($value)) {
                continue;
            }

            if (array_key_exists('public', $value)) {
                $fragments[] = new MetadataFragment(
                    key: 'authorization.public',
                    value: (bool) $value['public'],
                    origin: $origin,
                    priority: $this->priority(),
                );
            }

            if (isset($value['requirements']) && is_array($value['requirements'])) {
                $requirements = [];

                foreach ($value['requirements'] as $requirement) {
                    if (! is_array($requirement) || ! isset($requirement['ability']) || ! is_string($requirement['ability'])) {
                        continue;
                    }

                    $ability = trim($requirement['ability']);

                    if ($ability === '') {
                        continue;
                    }

                    $requirements[] = [
                        'ability' => $ability,
                        'subject' => $requirement['subject'] ?? null,
                        'source' => 'route',
                    ];
                }

                if ($requirements !== []) {
                    $fragments[] = new MetadataFragment(
                        key: 'authorization.requirements',
                        value: $requirements,
                        origin: $origin,
                        priority: $this->priority(),
                    );
                }
            }
        }

        return $fragments;
    }
}
