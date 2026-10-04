<?php

declare(strict_types=1);

namespace Quantum\Config\Schema;

use InvalidArgumentException;

final readonly class ConfigSchema
{
    public function __construct(
        private string $namespace,
        private ConfigNode $root,
    ) {
        if (trim($this->namespace) === '') {
            throw new InvalidArgumentException('Configuration schema namespace cannot be empty.');
        }

        if ($this->root->type() !== 'map') {
            throw new InvalidArgumentException('Configuration schema root must be a map node.');
        }
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function root(): ConfigNode
    {
        return $this->root;
    }
}
