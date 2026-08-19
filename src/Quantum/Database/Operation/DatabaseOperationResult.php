<?php declare(strict_types=1);

namespace Quantum\Database\Operation;

use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\QueryResult;

/**
 * Resultado exitoso/fallido de una operación de Database.
 */
final readonly class DatabaseOperationResult
{
    /**
     * @param array<string,mixed> $debug
     */
    private function __construct(
        public bool $isSuccess,
        public OperationKind $kind,
        public ?QueryResult $queryResult,
        public ?DbalException $error,
        public int $affectedRows = 0,
        public array $debug = [],
    ) {}

    public static function success(
        OperationKind $kind,
        QueryResult $qr,
        array $debug = [],
    ): self {
        return new self(true, $kind, $qr, null, $qr->affectedRows(), $debug);
    }

    public static function successNoRows(
        OperationKind $kind,
        int $affectedRows = 0,
        array $debug = [],
    ): self {
        return new self(true, $kind, null, null, $affectedRows, $debug);
    }

    public static function failure(
        OperationKind $kind,
        DbalException $e,
        array $debug = [],
    ): self {
        return new self(false, $kind, null, $e, 0, $debug);
    }

    public function orThrow(): self
    {
        if (!$this->isSuccess && $this->error !== null) {
            throw $this->error;
        }
        return $this;
    }
}
