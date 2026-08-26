<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseRemoteReplayValidationResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $status,
        public string $validator = 'unknown',
        public ?string $message = null,
        public array $details = [],
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public static function verified(
        string $validator = 'unknown',
        ?string $message = null,
        array $details = [],
    ): self {
        return new self('verified_remote_validation', $validator, $message, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function unavailable(
        string $validator = 'unknown',
        ?string $message = null,
        array $details = [],
    ): self {
        return new self('remote_validation_unavailable', $validator, $message, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function rejected(
        string $validator = 'unknown',
        ?string $message = null,
        array $details = [],
    ): self {
        return new self('remote_validation_rejected', $validator, $message, $details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'validator' => $this->validator,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
