<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use JsonSerializable;

final readonly class ContainerGraph implements JsonSerializable
{
    /**
     * @param array<string, ServiceNode> $services
     * @param array<string, string> $aliases
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        private array $services,
        private array $aliases,
        private array $issues,
    ) {
    }

    /**
     * @return array<string, ServiceNode>
     */
    public function services(): array
    {
        return $this->services;
    }

    public function service(string $abstract): ?ServiceNode
    {
        return $this->services[$abstract] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssues(): bool
    {
        return $this->issues !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'services' => array_map(
                static fn(ServiceNode $service): array => $service->toArray(),
                $this->services,
            ),
            'aliases' => $this->aliases,
            'issues' => array_map(
                static fn(ValidationIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
            'has_issues' => $this->hasIssues(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
