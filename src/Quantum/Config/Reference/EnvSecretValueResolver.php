<?php

declare(strict_types=1);

namespace Quantum\Config\Reference;

use RuntimeException;

final class EnvSecretValueResolver implements SecretValueResolverInterface
{
    public function resolve(string $key, string $provider = 'env', bool $required = true): mixed
    {
        $normalizedProvider = strtolower(trim($provider));

        if ($normalizedProvider !== '' && $normalizedProvider !== 'env') {
            throw new RuntimeException(sprintf('Secret provider [%s] is not supported.', $provider));
        }

        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            if ($required) {
                throw new RuntimeException(sprintf('Required secret [%s] is not available.', $key));
            }

            return null;
        }

        return $value;
    }
}
