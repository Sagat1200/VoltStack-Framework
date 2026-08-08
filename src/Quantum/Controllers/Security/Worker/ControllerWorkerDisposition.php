<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Worker;

final class ControllerWorkerDisposition
{
    public const Reuse = 0;
    public const ResetContext = 1;
    public const ContinueSafe = 2;
    public const Terminate = 3;
    public const TerminateOnTrustFailure = 4;

    private function __construct() {}

    public static function isTerminal(int $disposition): bool
    {
        return $disposition === self::Terminate || $disposition === self::TerminateOnTrustFailure;
    }

    public static function isTrustFailure(int $disposition): bool
    {
        return $disposition === self::TerminateOnTrustFailure;
    }

    public static function describe(int $disposition): string
    {
        return match ($disposition) {
            self::Reuse => 'reuse',
            self::ResetContext => 'reset_context',
            self::ContinueSafe => 'continue_safe',
            self::Terminate => 'terminate',
            self::TerminateOnTrustFailure => 'terminate_trust_failure',
            default => 'unknown(' . $disposition . ')',
        };
    }
}
