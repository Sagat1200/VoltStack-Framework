<?php

declare(strict_types=1);

namespace Quantum\Metadata\Subjects;

use Quantum\Metadata\Contracts\MetadataSubjectInterface;
use Quantum\Metadata\MetadataSubjectType;

final readonly class ControllerMethodSubject implements MetadataSubjectInterface
{
    public function __construct(
        private string $controllerClass,
        private string $method,
        private ?MetadataSubjectInterface $parent = null,
    ) {
    }

    public function controllerClass(): string
    {
        return $this->controllerClass;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function type(): MetadataSubjectType
    {
        return MetadataSubjectType::ControllerMethod;
    }

    public function id(): string
    {
        return $this->controllerClass . '@' . $this->method;
    }

    public function parent(): ?MetadataSubjectInterface
    {
        return $this->parent;
    }
}

