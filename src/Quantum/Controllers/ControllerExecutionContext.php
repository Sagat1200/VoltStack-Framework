<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Http\Request;
use Quantum\Routing\RouteMatch;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;

final class ControllerExecutionContext
{
    private ?ControllerSecurityContext $security = null;

    public function __construct(
        private readonly Request $request,
        private readonly RouteMatch $match,
    ) {}

    public function request(): Request
    {
        return $this->request;
    }

    public function match(): RouteMatch
    {
        return $this->match;
    }

    public function setSecurityContext(ControllerSecurityContext $security): void
    {
        if ($this->security !== null) {
            throw new \RuntimeException('Security context is already set and is immutable for this execution.');
        }
        $this->security = $security;
    }

    public function securityContext(): ?ControllerSecurityContext
    {
        return $this->security;
    }
}

