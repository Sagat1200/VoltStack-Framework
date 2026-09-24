<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Core\AuthorizationRequest;

interface AuthorizationRequestEnricherInterface
{
    public function enrich(AuthorizationRequest $request): AuthorizationRequest;
}
