<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Auth\Exceptions\AuthenticationException;

class ThrottleDeniedException extends AuthenticationException
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $message = 'Authentication throttled: too many failed attempts. Retry after the cooling period.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $retryAfterSeconds = 0,
        public readonly ?string $identifier = null,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message, 'auth.throttle_denied');
    }
}
