<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataProviderRegistry;

final class MetadataProviderPipeline
{
    public function __construct(private readonly MetadataProviderRegistry $registry) {}

    public function collect(MetadataRequest $request): array
    {
        $fragments = [];

        foreach ($this->registry->all() as $provider) {
            if (! $provider->supports($request)) {
                continue;
            }

            foreach ($provider->provide($request) as $fragment) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }
}
