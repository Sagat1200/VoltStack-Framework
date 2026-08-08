<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Exceptions;

use Throwable;

class AuthenticationRequiredException extends SecurityException
{
    /**
     * @param string                   $reasonCode        Machine-readable reason (e.g. "authentication_required", "authentication_strength_insufficient").
     * @param array<string, mixed>     $challengeMetadata Opaque data for challenge handlers (obligations, minimum_strength, schemes, etc).
     * @param array<string, mixed>     $safeContext       Non-sensitive diagnostic context safe to log or expose indirectly.
     * @param string                   $message
     * @param int                      $code
     * @param Throwable|null           $previous
     */
    public function __construct(
        public readonly string $reasonCode = 'authentication_required',
        public readonly array $challengeMetadata = [],
        public readonly array $safeContext = [],
        string $message = 'Authentication required',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return 'controller.security.authentication_required';
    }
}
