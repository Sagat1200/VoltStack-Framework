<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Normalization\ThrowableNormalizer;
use Quantum\Exceptions\Runtime\PhpBootstrapErrorBridge;
use Quantum\Exceptions\Runtime\PhpErrorCapturePolicy;

final class QuantumPhpBootstrapErrorBridgeTest extends TestCase
{
    public function test_it_converts_recoverable_errors_into_throwables_when_policy_requires_it(): void
    {
        $bridge = new PhpBootstrapErrorBridge(
            new PhpErrorCapturePolicy(),
            new ThrowableNormalizer(),
        );

        $previous = error_reporting(E_RECOVERABLE_ERROR);

        try {
            $this->expectException(\ErrorException::class);
            $this->expectExceptionMessage('recoverable failure');

            $bridge->handleError(E_RECOVERABLE_ERROR, 'recoverable failure', __FILE__, 42);
        } finally {
            error_reporting($previous);
        }
    }

    public function test_it_keeps_regular_warnings_on_the_legacy_path_by_default(): void
    {
        $bridge = new PhpBootstrapErrorBridge(
            new PhpErrorCapturePolicy(),
            new ThrowableNormalizer(),
        );

        $previous = error_reporting(E_WARNING);

        try {
            self::assertFalse($bridge->handleError(E_WARNING, 'legacy warning', __FILE__, 10));
        } finally {
            error_reporting($previous);
        }
    }

    public function test_it_renders_shutdown_problem_json_with_redacted_debug_message(): void
    {
        $bridge = new PhpBootstrapErrorBridge(
            new PhpErrorCapturePolicy(),
            new ThrowableNormalizer(),
        );

        $result = $bridge->renderShutdown(
            lastError: [
                'type' => E_ERROR,
                'message' => 'password=super-secret',
                'file' => 'C:\\app\\src\\Kernel.php',
                'line' => 99,
            ],
            server: [
                'HTTP_ACCEPT' => 'application/problem+json',
            ],
            debug: true,
        );

        self::assertNotNull($result);
        self::assertSame(500, $result->statusCode);
        self::assertSame('application/problem+json; charset=UTF-8', $result->headers['Content-Type'] ?? null);

        $payload = json_decode($result->body, true);

        self::assertIsArray($payload);
        self::assertSame('runtime.fatal_error', $payload['reason_code'] ?? null);
        self::assertSame('password=[REDACTED]', $payload['message'] ?? null);
        self::assertSame('FatalError', $payload['_debug']['error_type'] ?? null);
    }

    public function test_it_ignores_non_fatal_shutdown_errors_and_sent_headers(): void
    {
        $bridge = new PhpBootstrapErrorBridge(
            new PhpErrorCapturePolicy(),
            new ThrowableNormalizer(),
        );

        self::assertNull($bridge->renderShutdown([
            'type' => E_WARNING,
            'message' => 'warning only',
            'file' => __FILE__,
            'line' => 12,
        ]));

        self::assertNull($bridge->renderShutdown([
            'type' => E_ERROR,
            'message' => 'fatal',
            'file' => __FILE__,
            'line' => 12,
        ], headersAlreadySent: true));
    }
}
