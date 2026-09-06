<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use RuntimeException;

final class GuestOnlyException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'auth.guest_only',
        string $message = 'Only guests may access this resource.',
    ) {
        parent::__construct($message);
    }
}