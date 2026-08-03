<?php

declare(strict_types=1);

namespace Quantum\Controllers\Metadata;

use Quantum\Metadata\MetadataBag;

final readonly class ControllerMetadata
{
    public function __construct(private MetadataBag $bag)
    {
    }

    public function bag(): MetadataBag
    {
        return $this->bag;
    }

    public function interceptors(): array
    {
        $value = $this->bag->get('controller.interceptors', []);

        return is_array($value) ? $value : [];
    }
}

