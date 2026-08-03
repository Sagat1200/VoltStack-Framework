<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Contracts\MetadataSubjectInterface;

final readonly class MetadataRequest
{
    public function __construct(
        public MetadataSubjectInterface $subject,
        public array $keys = [],
        public array $scopes = [],
        public MetadataResolutionMode $mode = MetadataResolutionMode::Runtime,
    ) {
    }
}
