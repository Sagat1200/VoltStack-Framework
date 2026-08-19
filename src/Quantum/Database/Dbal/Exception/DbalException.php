<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Exception;

use Quantum\Database\Dbal\Enum\DatabaseFailureKind;

/**
 * Excepción base del DBAL. Todas las implementaciones de Connection lanzan
 * esta clase o subtipos. TIPIFICADA via DatabaseFailureKind (9 casos DDD-01).
 */
class DbalException extends \RuntimeException
{
    public function __construct(
        public readonly DatabaseFailureKind $kind,
        public readonly string $stage,
        public readonly ?string $sql = null,
        public readonly bool $retryable = false,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function wrap(
        \Throwable $t,
        DatabaseFailureKind $kind,
        string $stage,
        ?string $sql = null,
        bool $retryable = false,
        string $extraMsg = '',
    ): self {
        $msg = trim(sprintf('[DBAL:%s:%s] %s %s', $kind->value, $stage, $t->getMessage(), $extraMsg));
        return new self($kind, $stage, $sql, $retryable, $msg, (int)$t->getCode(), $t);
    }

    /**
     * Redacta credenciales potencialmente presentes en un mensaje de error
     * de conexión antes de devolverlo a la capa superior.
     */
    public static function redactMessage(string $message): string
    {
        $patterns = [
            '/(password|passwd|pwd|secret|token)\s*[=:]\s*["\']?[^"\'\s,;]+/i' => '$1=***REDACTED***',
            '/(password|passwd|pwd)\s*=\s*[^;\s]{4,}/i'                        => '$1=***REDACTED***',
        ];
        foreach ($patterns as $pattern => $replace) {
            $message = preg_replace($pattern, $replace, $message) ?? $message;
        }
        return $message;
    }
}
