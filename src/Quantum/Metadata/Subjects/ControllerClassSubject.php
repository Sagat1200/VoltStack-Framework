<?php

declare(strict_types=1);

namespace Quantum\Metadata\Subjects;

use Quantum\Metadata\Contracts\MetadataSubjectInterface;
use Quantum\Metadata\MetadataSubjectType;

final readonly class ControllerClassSubject implements MetadataSubjectInterface
{
    public function __construct(
        private string $controllerClass,
        private ?MetadataSubjectInterface $parent = null,
    ) {
    }

    public function controllerClass(): string
    {
        return $this->controllerClass;
    }

    public function type(): MetadataSubjectType
    {
        return MetadataSubjectType::Controller;
    }

    public function id(): string
    {
        return $this->controllerClass;
    }

    public function parent(): ?MetadataSubjectInterface
    {
        return $this->parent;
    }
}

