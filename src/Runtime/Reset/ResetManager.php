<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Reset;

use LogicException;
use Throwable;
use VoltStack\Framework\Application;

final class ResetManager
{
    /**
     * @var list<ResettableInterface|callable(Application): void|class-string<ResettableInterface>>
     */
    private array $resetters = [];

    /**
     * @param ResettableInterface|callable(Application): void|class-string<ResettableInterface> $resetter
     */
    public function register(ResettableInterface|string|callable $resetter): void
    {
        $this->resetters[] = $resetter;
    }

    public function reset(Application $app): ResetReport
    {
        $executed = 0;
        $errors = [];

        try {
            foreach ($this->resetters as $resetter) {
                $this->invokeResetter($app, $resetter);
                $executed++;
            }

            return new ResetReport(
                executedCount: $executed,
                successful: true,
            );
        } catch (Throwable $exception) {
            $errors[] = $exception;

            return new ResetReport(
                executedCount: $executed,
                successful: false,
                errors: $errors,
            );
        } finally {
            $app->flushScope();
        }
    }

    /**
     * @param ResettableInterface|callable(Application): void|class-string<ResettableInterface> $resetter
     */
    private function invokeResetter(Application $app, ResettableInterface|string|callable $resetter): void
    {
        if (is_string($resetter)) {
            $resetter = $app->make($resetter);
        }

        if ($resetter instanceof ResettableInterface) {
            $resetter->reset($app);

            return;
        }

        if (is_callable($resetter)) {
            $resetter($app);

            return;
        }

        throw new LogicException('ResetManager received an unsupported resetter definition.');
    }
}
