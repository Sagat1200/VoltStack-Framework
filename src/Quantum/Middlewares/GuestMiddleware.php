<?php

declare(strict_types=1);

namespace Quantum\Middlewares;

use Closure;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Exceptions\GuestOnlyException;
use Quantum\Http\Request;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;

final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationManagerInterface $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->auth->guest()) {
            return $next($request);
        }

        throw new GuestOnlyException();
    }
}
