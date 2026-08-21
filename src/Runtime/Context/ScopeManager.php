<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Context;

use Quantum\Http\Request;
use VoltStack\Framework\Application;

final class ScopeManager
{
    private ?RuntimeContext $context = null;

    public function __construct(private readonly Application $app) {}

    public function begin(Request $request): RuntimeContext
    {
        $this->app->flushScope();

        $context = new RuntimeContext(
            bin2hex(random_bytes(16)),
            $request,
            microtime(true),
        );

        RuntimeContext::setCurrent($context);
        $this->context = $context;

        $this->app->scopedInstance(Request::class, $request);
        $this->app->scopedInstance(RuntimeContext::class, $context);
        $this->app->fireScopeStart($context);

        return $context;
    }

    public function end(): void
    {
        $this->app->fireScopeEnd($this->context);
        $this->context = null;
        RuntimeContext::setCurrent(null);
        $this->app->flushScope();
    }

    public function current(): ?RuntimeContext
    {
        return $this->context ?? RuntimeContext::current();
    }
}
