<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Value;

/**
 * Resultado de compilar un SQG (o fragmento Raw/SQL).
 * @psalm-immutable
 */
final readonly class CompiledSql
{
    /**
     * @param string $sql SQL con placeholders compatibles con paramStyle.
     * @param list<mixed> $params parámetros posicionales (0-based). V1 siempre posicional.
     * @param list<SourceMapEntry> $sourceMap
     */
    public function __construct(
        public string $sql,
        public array $params,
        public int $paramCount,
        public string $fingerprint,
        public string $quoteStyle,
        public string $paramStyle,
        public array $sourceMap = [],
    ) {}

    public static function fingerprintFor(string $sqlWithoutParams): string
    {
        return hash('sha256', $sqlWithoutParams . '|voltstack-v1');
    }
}
