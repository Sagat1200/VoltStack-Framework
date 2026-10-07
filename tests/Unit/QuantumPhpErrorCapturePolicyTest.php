<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Runtime\PhpErrorCapturePolicy;

final class QuantumPhpErrorCapturePolicyTest extends TestCase
{
    public function test_it_respects_error_reporting_and_warning_conversion_flags(): void
    {
        $policy = new PhpErrorCapturePolicy(
            convertWarningsToExceptions: true,
            convertUserWarningsToExceptions: true,
            captureDeprecations: true,
        );

        self::assertTrue($policy->shouldConvertToException(E_WARNING, E_WARNING));
        self::assertTrue($policy->shouldConvertToException(E_USER_WARNING, E_USER_WARNING));
        self::assertFalse($policy->shouldConvertToException(E_WARNING, 0));
        self::assertFalse($policy->shouldConvertToException(E_NOTICE, E_NOTICE));
    }

    public function test_it_detects_deprecations_and_fatal_severities(): void
    {
        $policy = new PhpErrorCapturePolicy(captureDeprecations: true);

        self::assertTrue($policy->shouldCaptureDeprecation(E_DEPRECATED, E_DEPRECATED));
        self::assertTrue($policy->isDeprecation(E_USER_DEPRECATED));
        self::assertTrue($policy->isFatalSeverity(E_ERROR));
        self::assertTrue($policy->isFatalSeverity(E_PARSE));
        self::assertFalse($policy->isFatalSeverity(E_WARNING));
        self::assertSame('E_RECOVERABLE_ERROR', $policy->severityLabel(E_RECOVERABLE_ERROR));
    }
}
