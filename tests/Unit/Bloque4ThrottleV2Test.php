<?php

declare(strict_types=1);

namespace Quantum\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Exceptions\ThrottleDeniedException;

final class Bloque4ThrottleV2Test extends TestCase
{
    public function test_b4_01_throttle_exception_429_status(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 120);
        $this->assertSame(429, $mapper->statusCode($e));
        $this->assertSame('auth.throttle_denied', $e->reasonCode);
    }

    public function test_b4_02_throttle_retry_after_header_present(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 90, identifier: '10.0.0.5');
        $headers = $mapper->headers($e);
        $this->assertArrayHasKey('Retry-After', $headers);
        $this->assertSame('90', $headers['Retry-After']);
        $this->assertSame('true', $headers['X-Auth-Throttle-Denied']);
        $this->assertSame('10.0.0.5', $headers['X-Auth-Throttle-Identifier']);
    }

    public function test_b4_03_constructor_public_props_correct(): void
    {
        $e = new ThrottleDeniedException(
            message: 'Hold on',
            retryAfterSeconds: 15,
            identifier: 'usr-x',
            metadata: ['bucket' => 'login'],
        );
        $this->assertSame(15, $e->retryAfterSeconds);
        $this->assertSame('usr-x', $e->identifier);
        $this->assertSame(['bucket' => 'login'], $e->metadata);
        $this->assertSame('Hold on', $e->getMessage());
    }

    public function test_b4_04_json_extension_includes_reason_and_retry(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 45, identifier: 'idr-7');
        $ext = $mapper->jsonExtensions($e, true);
        $this->assertSame('auth.throttle_denied', $ext['reason_code']);
        $this->assertSame('45', $ext['retry_after_seconds']);
        $this->assertSame('idr-7', $ext['throttle_identifier']);
    }

    public function test_b4_05_distributed_counter_interface_shape(): void
    {
        $impl = new class implements DistributedThrottleCounterInterface {
            public function currentCount(string $bucketKey, ?int $now = null): int { return 7; }
            public function increment(string $bucketKey, int $windowSeconds, ?int $now = null): int { return 8; }
            public function reset(string $bucketKey): void {}
        };
        $this->assertInstanceOf(DistributedThrottleCounterInterface::class, $impl);
        $this->assertSame(7, $impl->currentCount('b1'));
        $this->assertSame(8, $impl->increment('b1', 60));
        $result = 0;
        try {
            $impl->reset('b1');
            $result = 1;
        } catch (\Throwable) {
            $result = -1;
        }
        $this->assertSame(1, $result);
    }

    public function test_b4_06_mapper_error_code_and_html_not_null(): void
    {
        $mapper = new AuthExceptionMapper();
        $e = new ThrottleDeniedException(retryAfterSeconds: 30);
        $this->assertSame('auth.throttle_denied', $mapper->errorCode($e, 429));
        $this->assertNotNull($mapper->htmlBody($e, 429));
        $this->assertStringContainsStringIgnoringCase('wait', $mapper->htmlBody($e, 429));
    }
}
