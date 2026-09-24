<?php

declare(strict_types=1);

namespace Quantum\Authorization\Policy\Contracts;

use Quantum\Authorization\Core\AuthorizationRequest;

interface SupportsAuthorizationRequest
{
    public function supports(AuthorizationRequest $request): bool;
}
