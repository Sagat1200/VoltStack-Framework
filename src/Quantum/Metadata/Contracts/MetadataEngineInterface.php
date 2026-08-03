<?php

declare(strict_types=1);

namespace Quantum\Metadata\Contracts;

use Quantum\Metadata\MetadataBag;
use Quantum\Metadata\MetadataRequest;

interface MetadataEngineInterface
{
    public function resolve(MetadataRequest $request): MetadataBag;
}

