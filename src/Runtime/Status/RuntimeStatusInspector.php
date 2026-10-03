<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Status;

use InvalidArgumentException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetBaselineResolver;
use VoltStack\Runtime\RuntimeManagerServer;

final class RuntimeStatusInspector
{
    public function inspect(Application $app, ?string $driver = null, int $maxRequests = 1): RuntimeStatusReport
    {
        /** @var RuntimeManagerServer $manager */
        $manager = $app->make(RuntimeManagerServer::class);
        $driver = strtolower(trim($driver ?? (string) $app->config('runtime.driver', 'frankenphp')));
        $alerts = [];

        try {
            $adapter = $manager->adapter($driver);
            $capabilities = $adapter->capabilities();
            $recommendedBudget = (new RuntimeBudgetBaselineResolver())->resolve($app, $driver, $capabilities);
        } catch (InvalidArgumentException) {
            return new RuntimeStatusReport(
                driver: $driver === '' ? 'unknown' : $driver,
                maxRequests: max(1, $maxRequests),
                persistent: false,
                concurrent: false,
                streaming: false,
                drainControl: false,
                nativeHttp: false,
                recommendedBudget: new RuntimeBudgetBaseline(
                    driver: $driver === '' ? 'unknown' : $driver,
                    totalMaximumMs: null,
                    requestMaximumMs: null,
                    source: 'unavailable',
                ),
                supportedDrivers: $manager->drivers(),
                alerts: ['El driver runtime solicitado no esta registrado.'],
            );
        }

        if ($maxRequests < 1) {
            $alerts[] = 'maxRequests debe ser mayor o igual a 1.';
        }

        return new RuntimeStatusReport(
            driver: $driver,
            maxRequests: max(1, $maxRequests),
            persistent: $capabilities->persistent(),
            concurrent: $capabilities->concurrent(),
            streaming: $capabilities->streaming(),
            drainControl: $capabilities->drainControl(),
            nativeHttp: $capabilities->nativeHttp(),
            recommendedBudget: $recommendedBudget,
            supportedDrivers: $manager->drivers(),
            alerts: $alerts,
        );
    }
}
