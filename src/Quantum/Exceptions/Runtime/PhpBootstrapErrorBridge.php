<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;

final readonly class PhpBootstrapErrorBridge
{
    public function __construct(
        private PhpErrorCapturePolicy $policy,
        private ExceptionNormalizerInterface $normalizer,
    ) {
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (! $this->policy->shouldConvertToException($severity, error_reporting())) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * @param array<string, mixed>|null $lastError
     * @param array<string, mixed> $server
     */
    public function renderShutdown(
        ?array $lastError,
        array $server = [],
        bool $debug = false,
        bool $headersAlreadySent = false,
    ): ?PhpShutdownRenderResult {
        if ($headersAlreadySent || $lastError === null) {
            return null;
        }

        $severity = $lastError['type'] ?? null;

        if (! is_int($severity) || ! $this->policy->isFatalSeverity($severity)) {
            return null;
        }

        $message = is_string($lastError['message'] ?? null) && $lastError['message'] !== ''
            ? $lastError['message']
            : 'Unknown fatal';
        $file = is_string($lastError['file'] ?? null) && $lastError['file'] !== ''
            ? $lastError['file']
            : 'unknown';
        $line = is_int($lastError['line'] ?? null) ? $lastError['line'] : (int) ($lastError['line'] ?? 0);
        $errorCode = $this->errorCode($severity);
        $errorType = $this->errorType($severity);
        $snapshot = $this->normalizer->normalize(
            new \ErrorException($message, 0, $severity, $file, $line),
            new ExceptionContext(
                scopeId: 'bootstrap.shutdown',
                debug: $debug,
                attributes: [
                    'origin' => 'bootstrap.shutdown',
                    'php_error_severity' => $this->policy->severityLabel($severity),
                ],
            ),
        );

        if ($this->isVoltRequest($server)) {
            return new PhpShutdownRenderResult(
                statusCode: 500,
                headers: [
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'X-Volt-Error-Code' => $errorCode,
                ],
                body: (string) json_encode([
                    'error' => [
                        'type' => 'runtime.' . $errorType,
                        'kind' => 'fatal',
                        'code' => $errorCode,
                        'status' => 500,
                        'message' => $debug ? $snapshot->internalMessage : 'Server Error',
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }

        if ($this->expectsJson($server)) {
            $payload = [
                'title' => 'Internal Server Error',
                'status' => 500,
                'reason_code' => $errorCode,
                'message' => $debug
                    ? $snapshot->internalMessage
                    : 'An unexpected error occurred while processing the request.',
            ];

            if ($debug) {
                $payload['_debug'] = [
                    'error_type' => $errorType,
                    'file' => $file,
                    'line' => $line,
                ];
            }

            return new PhpShutdownRenderResult(
                statusCode: 500,
                headers: [
                    'Content-Type' => 'application/problem+json; charset=UTF-8',
                ],
                body: (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }

        $debugHtml = $debug
            ? '<div style="margin-block-start:20px; padding:14px; background:#0b1220; border:1px solid #334155; border-radius:8px;">'
                . '<p style="margin:0 0 8px 0;"><strong style="color:#fca5a5;">FATAL SHUTDOWN:</strong> <code style="color:#f87171;">' . htmlspecialchars($errorType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>'
                . '<p style="margin:0 0 8px 0;"><strong>Message:</strong> <code>' . htmlspecialchars($snapshot->internalMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>'
                . '<p style="margin:0 0 8px 0;"><strong>Location:</strong> <code>' . htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':' . $line . '</code></p>'
                . '</div>'
            : '';

        return new PhpShutdownRenderResult(
            statusCode: 500,
            headers: [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Volt-Error-Code' => $errorCode,
            ],
            body: <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="volt-document" content="reload"><title>Server Error</title>
<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}main{max-inline-size:720px;margin:0 auto;background:#111827;border:1px solid #334155;border-radius:12px;padding:32px;}h1{margin-block-start:0;}code{background:#1e293b;padding:2px 6px;border-radius:4px;}</style>
</head><body data-volt-document="reload"><main><h1>Server Error</h1><p>An unexpected error occurred while processing the request.</p>{$debugHtml}</main></body></html>
HTML,
        );
    }

    /**
     * @param array<string, mixed> $server
     */
    private function expectsJson(array $server): bool
    {
        $accept = strtolower(trim((string) ($server['HTTP_ACCEPT'] ?? '')));

        return $accept !== ''
            && (str_contains($accept, 'application/json') || str_contains($accept, 'application/problem+json'));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function isVoltRequest(array $server): bool
    {
        return ($server['HTTP_X_REQUESTED_WITH'] ?? null) === 'VoltStack'
            || ($server['HTTP_X_VOLT_NAVIGATE'] ?? null) === 'true';
    }

    private function errorType(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'FatalError',
            E_PARSE => 'ParseError',
            E_CORE_ERROR => 'CoreError',
            E_COMPILE_ERROR => 'CompileError',
            E_USER_ERROR => 'UserError',
            E_RECOVERABLE_ERROR => 'RecoverableError',
            default => 'UnknownFatal',
        };
    }

    private function errorCode(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'runtime.fatal_error',
            E_PARSE => 'runtime.parse_error',
            E_CORE_ERROR => 'runtime.core_error',
            E_COMPILE_ERROR => 'runtime.compile_error',
            E_USER_ERROR => 'runtime.user_error',
            E_RECOVERABLE_ERROR => 'runtime.recoverable_error',
            default => 'server.error',
        };
    }
}
