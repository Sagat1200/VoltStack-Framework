<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AbuseProtection\ThrottleDecision;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Exceptions\ThrottleDeniedException;

final class BloqueI1ThrottleV2Test extends TestCase
{
    public function test_throttle_denied_exception_inherits_auth_exception_reason_code(): void
    {
        $e = new ThrottleDeniedException(retryAfterSeconds: 300, identifier: 'user@example.com');
        self::assertInstanceOf(\Quantum\Auth\Exceptions\AuthenticationException::class, $e);
        self::assertSame('auth.throttle_denied', $e->reasonCode);
        self::assertSame(300, $e->retryAfterSeconds);
        self::assertSame('user@example.com', $e->identifier);
        self::assertStringContainsString('throttled', $e->getMessage());
    }

    public function test_auth_exception_mapper_maps_throttle_denied_to_status_429_too_many_requests(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 120);
        self::assertSame(429, $mapper->statusCode($e));
    }

    public function test_auth_exception_mapper_headers_include_retry_after_and_throttle_custom_headers(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 30, identifier: 'login:usr1');
        $headers = $mapper->headers($e);
        self::assertArrayHasKey('Retry-After', $headers);
        self::assertSame('30', $headers['Retry-After']);
        self::assertArrayHasKey('X-Auth-Throttle-Retry-After', $headers);
        self::assertSame('30', $headers['X-Auth-Throttle-Retry-After']);
        self::assertArrayHasKey('X-Auth-Throttle-Identifier', $headers);
        self::assertSame('login:usr1', $headers['X-Auth-Throttle-Identifier']);
        self::assertArrayHasKey('X-Auth-Throttle-Denied', $headers);
        self::assertSame('true', $headers['X-Auth-Throttle-Denied']);
    }

    public function test_auth_exception_mapper_omits_retry_after_when_retry_zero(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 0);
        $headers = $mapper->headers($e);
        self::assertArrayNotHasKey('Retry-After', $headers, 'retryAfter=0 must exclude Retry-After header (standard: only positive values)');
        self::assertArrayHasKey('X-Auth-Throttle-Retry-After', $headers);
        self::assertSame('0', $headers['X-Auth-Throttle-Retry-After']);
        self::assertArrayNotHasKey('X-Auth-Throttle-Identifier', $headers);
    }

    public function test_reason_code_extension_json_and_volt_propagate_retry_after_and_identifier(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 180, identifier: 'ip:10.0.0.5');
        $json = $mapper->jsonExtensions($e, false);
        self::assertSame('auth.throttle_denied', $json['reason_code'] ?? null);
        self::assertSame('180', $json['retry_after_seconds'] ?? null);
        self::assertSame('ip:10.0.0.5', $json['throttle_identifier'] ?? null);
        $volt = $mapper->voltExtensions($e, false);
        self::assertSame($json, $volt, 'jsonExtensions must equal voltExtensions for throttle denied envelope');
    }

    public function test_throttle_decision_deny_retry_after_seconds_propagates_from_engine_to_exception(): void
    {
        $deny = ThrottleDecision::deny('login_rate_exceeded', 90, ['ip_prefix' => '192.168.1', 'bucket_key' => 'log:u1']);
        self::assertTrue($deny->isDenied());
        self::assertFalse($deny->isAllowed());
        self::assertSame(90, $deny->retryAfterSeconds);
        self::assertSame('login_rate_exceeded', $deny->reasonCode);
        self::assertSame('192.168.1', $deny->metadata['ip_prefix'] ?? null);

        $reconstructedEx = new ThrottleDeniedException(
            retryAfterSeconds: $deny->retryAfterSeconds,
            identifier: (string)($deny->metadata['bucket_key'] ?? 'unknown'),
            metadata: $deny->metadata,
        );
        self::assertSame(90, $reconstructedEx->retryAfterSeconds);
        self::assertSame('log:u1', $reconstructedEx->identifier);
        $mapper = new AuthExceptionMapper();
        self::assertSame(429, $mapper->statusCode($reconstructedEx));
        self::assertSame('90', $mapper->headers($reconstructedEx)['Retry-After'] ?? null);
    }
}
