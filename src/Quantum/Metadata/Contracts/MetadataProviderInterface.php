<?php

declare(strict_types=1);

namespace Quantum\Metadata\Contracts;

use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataRequest;

interface MetadataProviderInterface
{
    public function name(): string;

    public function priority(): int;

    public function supports(MetadataRequest $request): bool;

    /**
     * @return array<int, MetadataFragment>
     */
    public function provide(MetadataRequest $request): array;
}

