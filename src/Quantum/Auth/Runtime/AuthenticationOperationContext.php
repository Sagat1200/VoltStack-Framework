<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;

final class AuthenticationOperationContext
{
    public function __construct(
        public readonly string $operation,
        public readonly AuthenticationRequest $request,
        public ?AuthenticationContext $currentContext = null,
    ) {
    }
}
