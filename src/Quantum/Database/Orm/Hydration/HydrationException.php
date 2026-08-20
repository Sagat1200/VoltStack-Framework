<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Hydration;

/**
 * Hydration Exception.
 */
class HydrationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'DB_MAP_0000',
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$errorCode}] {$message}", 0, $previous);
    }
}
