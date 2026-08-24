<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationVerificationResult
{
    /**
     * @param list<string> $verifiedVersions
     * @param list<string> $remainingPendingVersions
     */
    public function __construct(
        public bool $verified,
        public string $fingerprint,
        public ?int $batchNumber,
        public array $verifiedVersions,
        public array $remainingPendingVersions,
    ) {}

    public function verifiedCount(): int
    {
        return count($this->verifiedVersions);
    }

    public function remainingPendingCount(): int
    {
        return count($this->remainingPendingVersions);
    }
}