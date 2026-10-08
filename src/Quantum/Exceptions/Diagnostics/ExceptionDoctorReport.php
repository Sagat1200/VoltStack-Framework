<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use JsonSerializable;

final readonly class ExceptionDoctorReport implements JsonSerializable
{
    /**
     * @param array<int, string> $effects
     * @param array<int, string> $warnings
     * @param array<int, string> $issues
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public string $schemaVersion,
        public string $operationId,
        public string $outcome,
        public array $effects,
        public array $warnings,
        public array $issues,
        public array $configuration,
        public array $diagnostics,
    ) {
    }

    public function healthy(): bool
    {
        return $this->issues === [] && $this->warnings === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'operation_id' => $this->operationId,
            'outcome' => $this->outcome,
            'effects' => $this->effects,
            'warnings' => $this->warnings,
            'issues' => $this->issues,
            'configuration' => $this->configuration,
            'diagnostics' => $this->diagnostics,
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
