<?php declare(strict_types=1);

namespace Quantum\Database\Trace;

/**
 * Contexto de tracing (W3C Trace Context compatible).
 */
final readonly class DatabaseTraceContext
{
    public function __construct(
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId = null,
    ) {}

    public static function random(): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            spanId: bin2hex(random_bytes(8)),
        );
    }
}
