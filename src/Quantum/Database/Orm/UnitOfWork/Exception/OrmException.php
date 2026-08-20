<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Exception;

/**
 * Excepción base ORM (UoW/IM/ChangeTracking/Associations usan esta familia).
 */
class OrmException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ORM_0000',
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[{$errorCode}] {$message}", 0, $previous);
    }
}
