<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Contracts\MetadataSubjectInterface;

final readonly class MetadataRequest
{
    /**
     * @param array<int, string> $keys
     * @param array<int, string> $scopes
     */
    public function __construct(
        public MetadataSubjectInterface $subject,
        public array $keys = [],
        public array $scopes = [],
        public MetadataResolutionMode $mode = MetadataResolutionMode::Runtime,
    ) {
    }
}

