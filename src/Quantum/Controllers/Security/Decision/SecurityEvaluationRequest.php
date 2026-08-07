<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Decision;

use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\ControllerTarget;

final readonly class SecurityEvaluationRequest
{
    public function __construct(
        public ControllerSecurityContext $security,
        public ControllerTarget $target,
        public string $action,
        public mixed $resource,
        public array $metadata = [],
    ) {}
}
