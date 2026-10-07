<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class PublicError
{
    /**
     * @param array<int, array<string, scalar|array|null>>|null $fields
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $occurrenceId,
        public ?array $fields = null,
    ) {
        if ($code === '') {
            throw new \InvalidArgumentException('PublicError code must not be empty.');
        }

        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('PublicError occurrenceId must not be empty.');
        }
    }
}
