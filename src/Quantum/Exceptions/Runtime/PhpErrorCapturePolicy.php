<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

final readonly class PhpErrorCapturePolicy
{
    public function __construct(
        public bool $convertWarningsToExceptions = false,
        public bool $convertUserWarningsToExceptions = false,
        public bool $captureDeprecations = true,
    ) {
    }

    public function shouldConvertToException(int $severity, ?int $errorReporting = null): bool
    {
        $activeMask = $errorReporting ?? error_reporting();

        if (($activeMask & $severity) === 0) {
            return false;
        }

        return match ($severity) {
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => $this->convertWarningsToExceptions,
            E_NOTICE, E_USER_NOTICE => false,
            E_USER_WARNING => $this->convertUserWarningsToExceptions,
            E_DEPRECATED, E_USER_DEPRECATED => false,
            E_RECOVERABLE_ERROR => true,
            default => false,
        };
    }

    public function shouldCaptureDeprecation(int $severity, ?int $errorReporting = null): bool
    {
        if (! $this->captureDeprecations) {
            return false;
        }

        $activeMask = $errorReporting ?? error_reporting();

        return $this->isDeprecation($severity) && (($activeMask & $severity) !== 0);
    }

    public function isDeprecation(int $severity): bool
    {
        return $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
    }

    public function isFatalSeverity(int $severity): bool
    {
        return in_array($severity, [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true);
    }

    public function severityLabel(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }

}
