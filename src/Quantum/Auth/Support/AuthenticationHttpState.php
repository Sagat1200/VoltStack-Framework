<?php

declare(strict_types=1);

namespace Quantum\Auth\Support;

final class AuthenticationHttpState
{
    public const SESSION_COOKIE_NAME = 'voltstack_auth_session';
    public const ACTIVE_SESSION_ID_KEY = 'auth.active_session_id';
    public const PENDING_SESSION_COOKIE_KEY = 'auth.pending_session_cookie';
    public const PENDING_SESSION_HEADER_KEY = 'auth.pending_session_header';

    public static function loginCookie(string $sessionId, string $cookieName = self::SESSION_COOKIE_NAME, ?int $maxAge = null): string
    {
        $cookie = sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Lax',
            $cookieName,
            rawurlencode($sessionId),
        );

        if ($maxAge !== null && $maxAge > 0) {
            $cookie .= '; Max-Age=' . $maxAge;
        }

        return $cookie;
    }

    public static function logoutCookie(string $cookieName = self::SESSION_COOKIE_NAME): string
    {
        return sprintf(
            '%s=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; HttpOnly; SameSite=Lax',
            $cookieName,
        );
    }
}
