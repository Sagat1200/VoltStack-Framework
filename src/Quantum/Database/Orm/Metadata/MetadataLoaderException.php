<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * Excepción genérica ORM metadata.
 */
class MetadataLoaderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'META_ORM_0000',
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$errorCode}] {$message}", 0, $previous);
    }
}
