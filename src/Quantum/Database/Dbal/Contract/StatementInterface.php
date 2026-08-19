<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Contract;

use Quantum\Database\Dbal\Enum\ParamType;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\QueryResult;

/**
 * Statement preparado autoritativo.
 */
interface StatementInterface
{
    /**
     * @param int $index 0-based
     */
    public function bindValue(int $index, mixed $value, ParamType $type = ParamType::Auto): void;

    /**
     * @param list<mixed> $extraParams bindings posicionales adicionales al final
     *
     * @throws DbalException
     */
    public function execute(array $extraParams = []): QueryResult;

    public function closeCursor(): void;

    /**
     * @return int<0,max> número de placeholders '?' detectados en el SQL
     */
    public function paramCount(): int;
}
