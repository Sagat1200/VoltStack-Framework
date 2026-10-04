<?php

declare(strict_types=1);

namespace Quantum\Config;

use InvalidArgumentException;

final readonly class ConfigDocument
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private SourceDescriptor $descriptor,
        private array $payload,
        private string $schemaVersion = '1.0.0',
    ) {
        if (trim($this->schemaVersion) === '') {
            throw new InvalidArgumentException('Config document schema version cannot be empty.');
        }
    }

    public function descriptor(): SourceDescriptor
    {
        return $this->descriptor;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }
}
