<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Support\AuthenticationHttpState;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Runtime\Context\RuntimeContext;

final class AuthenticationResponseDecorator
{
    public function decorate(Request $request, Response $response): Response
    {
        $context = RuntimeContext::current();

        if ($context === null) {
            return $response;
        }

        $pendingCookie = $context->get(AuthenticationHttpState::PENDING_SESSION_COOKIE_KEY);
        if (is_string($pendingCookie) && trim($pendingCookie) !== '') {
            $response->header('Set-Cookie', $pendingCookie);
        }

        $pendingTrustedDeviceCookie = $context->get(AuthenticationHttpState::PENDING_TRUSTED_DEVICE_COOKIE_KEY);
        if (is_string($pendingTrustedDeviceCookie) && trim($pendingTrustedDeviceCookie) !== '') {
            $response->header('Set-Cookie', $pendingTrustedDeviceCookie);
        }

        $pendingHeader = $context->get(AuthenticationHttpState::PENDING_SESSION_HEADER_KEY);
        if (is_string($pendingHeader) && trim($pendingHeader) !== '') {
            $response->header('X-Auth-Session', $pendingHeader);
        }

        return $response;
    }
}
