<?php

declare(strict_types=1);

namespace Quantum\Middlewares;

use Closure;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Exceptions\AuthenticationRequiredException;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Http\Request;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;

final class BearerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationManagerInterface $auth,
        private readonly OpaqueTokenRepositoryInterface $tokens,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $accessTokenId = $this->extractAccessTokenId($request);

        if ($accessTokenId !== null && trim($accessTokenId) !== '') {
            if (! $this->auth->check()) {
                $attributes = array_merge(
                    $request->attributes,
                    ['access_token' => $accessTokenId],
                );
                $headers = array_merge(
                    $request->headers,
                    ['authorization' => 'Bearer ' . $accessTokenId],
                );
                $clone = $request->withAttributes($attributes)->withHeaders($headers);
                $authenticated = $this->auth->authenticate($clone);

                if (! $authenticated) {
                    throw new AuthenticationRequiredException();
                }
            }
        }

        if (! $this->auth->check()) {
            throw new AuthenticationRequiredException();
        }

        return $next($request);
    }

    private function extractAccessTokenId(Request $request): ?string
    {
        $header = $request->header('Authorization');
        $header = is_string($header) ? trim($header) : '';

        if ($header === '') {
            $alt = $request->header('authorization');
            $header = is_string($alt) ? trim($alt) : '';
        }

        if ($header === '') {
            return null;
        }

        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
