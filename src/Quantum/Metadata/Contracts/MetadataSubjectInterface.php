<?php

declare(strict_types=1);

namespace Quantum\Metadata\Contracts;

use Quantum\Metadata\MetadataSubjectType;

interface MetadataSubjectInterface
{
    public function type(): MetadataSubjectType;

    public function id(): string;

    public function parent(): ?MetadataSubjectInterface;
}