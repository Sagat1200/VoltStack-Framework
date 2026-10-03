<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

use VoltStack\Framework\Application;
use VoltStack\Runtime\RuntimeCapabilities;

final class RuntimeBudgetBaselineResolver
{
    public function resolve(
        Application $app,
        string $driver,
        ?RuntimeCapabilities $capabilities = null,
    ): RuntimeBudgetBaseline {
        $driver = strtolower(trim($driver));
        $configured = $app->config('runtime.budgets.drivers.' . $driver, []);

        if (is_array($configured)) {
            $total = $this->normalizeBudget($configured['total_ms'] ?? null);
            $request = $this->normalizeBudget($configured['request_ms'] ?? null);

            if ($total !== null || $request !== null) {
                return new RuntimeBudgetBaseline(
                    driver: $driver,
                    totalMaximumMs: $total,
                    requestMaximumMs: $request,
                    source: 'config',
                );
            }
        }

        if ($driver === 'frankenphp') {
            return new RuntimeBudgetBaseline(
                driver: $driver,
                totalMaximumMs: 50.0,
                requestMaximumMs: 25.0,
                source: 'adapter-default',
            );
        }

        if ($capabilities?->persistent() === true && $capabilities->concurrent() === false) {
            return new RuntimeBudgetBaseline(
                driver: $driver,
                totalMaximumMs: 75.0,
                requestMaximumMs: 35.0,
                source: 'capability-default',
            );
        }

        if ($capabilities?->persistent() === true) {
            return new RuntimeBudgetBaseline(
                driver: $driver,
                totalMaximumMs: 100.0,
                requestMaximumMs: 50.0,
                source: 'capability-default',
            );
        }

        return new RuntimeBudgetBaseline(
            driver: $driver,
            totalMaximumMs: 150.0,
            requestMaximumMs: 75.0,
            source: 'capability-default',
        );
    }

    private function normalizeBudget(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $numeric = (float) $value;

        return $numeric > 0 ? $numeric : null;
    }
}
