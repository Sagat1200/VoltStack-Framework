<?php

declare(strict_types=1);

namespace Quantum\Config;

use InvalidArgumentException;

final readonly class SourceDescriptor
{
    public function __construct(
        private string $id,
        private string $namespace,
        private string $layer = 'application',
        private int $ordinal = 0,
        private ?string $location = null,
        private ?string $revision = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Source descriptor id cannot be empty.');
        }

        if (trim($this->namespace) === '') {
            throw new InvalidArgumentException('Source descriptor namespace cannot be empty.');
        }

        if (trim($this->layer) === '') {
            throw new InvalidArgumentException('Source descriptor layer cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function layer(): string
    {
        return $this->layer;
    }

    public function ordinal(): int
    {
        return $this->ordinal;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function revision(): ?string
    {
        return $this->revision;
    }

    /**
     * @return array{
     *     id: string,
     *     namespace: string,
     *     layer: string,
     *     ordinal: int,
     *     location: ?string,
     *     revision: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'namespace' => $this->namespace,
            'layer' => $this->layer,
            'ordinal' => $this->ordinal,
            'location' => $this->location,
            'revision' => $this->revision,
        ];
    }
}
