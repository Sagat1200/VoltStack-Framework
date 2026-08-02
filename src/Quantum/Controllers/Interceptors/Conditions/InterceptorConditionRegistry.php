<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Conditions;

use Quantum\Controllers\Exceptions\UnknownInterceptorConditionException;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use VoltStack\Framework\Application;

final class InterceptorConditionRegistry
{
    private array $conditions = [];

    public function __construct(private readonly Application $app) {}

    public function register(string $type, string $condition): void
    {
        $this->conditions[$type] = $condition;
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
