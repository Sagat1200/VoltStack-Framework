<?php

declare(strict_types=1);

namespace Quantum\Metadata\Subjects;

use Quantum\Metadata\Contracts\MetadataSubjectInterface;
use Quantum\Metadata\MetadataSubjectType;
use Quantum\Routing\RouteMatch;

final readonly class RouteMatchSubject implements MetadataSubjectInterface
{
    public function __construct(private RouteMatch $match)
    {
    }

    public function match(): RouteMatch
    {
        return $this->match;
    }

    public function type(): MetadataSubjectType
    {
        return MetadataSubjectType::Route;
    }

    public function id(): string
    {
        $route = $this->match->route();

        return implode('|', [
            implode(',', $route->methods()),
            $route->uri(),
        ]);
    }

    public function parent(): ?MetadataSubjectInterface
    {
        return null;
    }
}

