<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Interceptors\InterceptorScope;

final readonly class InterceptorDescriptor
{
    public function __construct(
        public string $id,
        public string $interceptor,
        public InterceptorScope $scope,
        public int $defaultPriority,
        public InterceptorPhase $defaultPhase,
        public array $tags = [],
        public bool $repeatable = false,
        public bool $compilable = true,
        public bool $stateless = false,
        public array $supportedContexts = ['controller'],
    ) {
    }
}
