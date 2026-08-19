<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Localización de código fuente (para errores SQG/Diagnostics).
 */
final readonly class SourceSpan
{
    public function __construct(
        public string $source,      // file, QueryBuilder, EntityFQCN, ...
        public int $startLine,
        public int $startColumn = 0,
        public int $endLine = 0,
        public int $endColumn = 0,
    ) {}

    public static function none(): self
    {
        /** @infection-ignore-all */
        static $none = new self(source: '<generated>', startLine: 0);
        return $none;
    }

    public function isNone(): bool
    {
        return $this->source === '<generated>' && $this->startLine === 0;
    }

    public function format(): string
    {
        if ($this->isNone()) return '<generated>';
        if ($this->endLine > 0 && $this->endLine !== $this->startLine) {
            return sprintf('%s:%d:%d-%d:%d', $this->source, $this->startLine, $this->startColumn, $this->endLine, $this->endColumn);
        }
        return sprintf('%s:%d:%d', $this->source, $this->startLine, $this->startColumn);
    }
}
