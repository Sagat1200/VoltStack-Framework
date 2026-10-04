<?php

declare(strict_types=1);

namespace Quantum\Config\Reference;

use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use RuntimeException;

final class ConfigReferenceResolver
{
    public function __construct(
        private readonly ?SecretValueResolverInterface $secretResolver = null,
    ) {
    }

    public function resolve(mixed $value): mixed
    {
        if ($value instanceof EnvReference) {
            return $this->resolveEnvReference($value);
        }

        if ($value instanceof SecretReference) {
            return $this->resolveSecretReference($value);
        }

        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                $resolved[$key] = $this->resolve($item);
            }

            return $resolved;
        }

        return $value;
    }

    private function resolveEnvReference(EnvReference $reference): mixed
    {
        $name = $reference->name();
        $value = $_ENV[$name] ?? getenv($name);

        if ($value === false || $value === null || $value === '') {
            if ($reference->required()) {
                throw new RuntimeException(sprintf('Required environment variable [%s] is not available.', $name));
            }

            return $this->resolve($reference->default());
        }

        return $value;
    }

    private function resolveSecretReference(SecretReference $reference): mixed
    {
        $resolver = $this->secretResolver ?? new EnvSecretValueResolver();

        return $resolver->resolve(
            key: $reference->key(),
            provider: $reference->provider(),
            required: $reference->required(),
        );
    }
}
