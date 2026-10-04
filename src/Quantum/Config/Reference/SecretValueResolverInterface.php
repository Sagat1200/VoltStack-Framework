<?php

declare(strict_types=1);

namespace Quantum\Config\Reference;

interface SecretValueResolverInterface
{
    public function resolve(string $key, string $provider = 'env', bool $required = true): mixed;
}
