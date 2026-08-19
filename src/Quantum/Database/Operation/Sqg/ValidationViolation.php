<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Violación de validación del SQG. 5-pass pipeline produce list<Violation>.
 */
final readonly class ValidationViolation
{
    public function __construct(
        public string $passName,
        public string $level,       // 'error' | 'warning' | 'info'
        public string $code,        // 'V1001' etc
        public string $message,
        public ?string $nodeId = null,
        public ?SourceSpan $span = null,
    ) {}

    public function isError(): bool { return $this->level === 'error'; }
}
