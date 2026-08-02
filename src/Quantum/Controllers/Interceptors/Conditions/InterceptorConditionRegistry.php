<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions;

use Quantum\Controllers\Exceptions\UnknownInterceptorConditionException;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use VoltStack\Framework\Application;

final class InterceptorConditionRegistry
{
    private array $conditions = [];
    private array $aliases = [];

    public function __construct(private readonly Application $app) {}

    public function register(string $type, string $condition): void
    {
        $this->conditions[$type] = $condition;
    }

    public function alias(string $alias, string $type, mixed $defaultValue = null): void
    {
        $this->aliases[$alias] = [
            'type' => $type,
            'defaultValue' => $defaultValue,
        ];
    }

    public function makeFrom(string $typeOrAlias, mixed $value = null): InterceptorConditionInterface
    {
        if (isset($this->aliases[$typeOrAlias])) {
            $alias = $this->aliases[$typeOrAlias];

            return $this->make((string) $alias['type'], $value ?? ($alias['defaultValue'] ?? null));
        }

        return $this->make($typeOrAlias, $value);
    }

    public function make(string $type, mixed $value): InterceptorConditionInterface
    {
        if (! isset($this->conditions[$type])) {
            throw new UnknownInterceptorConditionException($type);
        }

        $class = $this->conditions[$type];

        return $this->app->make($class, [
            'value' => $value,
        ]);
    }
}