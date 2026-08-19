<?php

declare(strict_types=1);

namespace Quantum\Database\Dbal\Driver\PdoCommon;

use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\ParamType;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\ColumnMeta;
use Quantum\Database\Dbal\Value\QueryResult;

/**
 * Statement PDO base compartido por todos los drivers PDO.
 * Maneja binding 0-indexed → PDO 1-indexed + type coercion.
 */
final class AbstractPdoStatement implements StatementInterface
{
    /** @var array<int,array{0:mixed,1:ParamType}> */
    private array $bindings = [];
    private readonly int $paramCount;

    public function __construct(
        private readonly \PDOStatement $stmt,
        private readonly AbstractPdoConnection $owner,
        private readonly string $sql,
        private readonly ExceptionMapperInterface_Placeholder $mapper,
    ) {
        $this->paramCount = substr_count($this->owner->normalizeToQMarks($sql), '?');
        $this->owner->trackPdoStatement($this->stmt);
    }

    public function __destruct()
    {
        $this->owner->untrackPdoStatement($this->stmt);
    }

    public function paramCount(): int
    {
        return $this->paramCount;
    }

    public function bindValue(int $index, mixed $value, ParamType $type = ParamType::Auto): void
    {
        if ($index < 0) {
            throw new DbalException(
                kind: DbalExceptionKind_Encap::Validation(),
                stage: 'bindValue',
                sql: $this->sql,
                message: "Invalid negative index $index"
            );
        }
        $resolvedType = $type;
        if ($type === ParamType::Auto) {
            $resolvedType = match (true) {
                $value === null                => ParamType::Null,
                is_bool($value)                => ParamType::Bool,
                is_int($value)                 => ParamType::Int,
                is_resource($value)            => ParamType::LOB,
                default                        => ParamType::Str,
            };
        }
        $this->bindings[$index] = [$value, $resolvedType];
    }

    public function execute(array $extraParams = []): QueryResult
    {
        // Mezclar bindings indexados + extraParams al final (append).
        $all = $this->bindings;
        $startIdx = empty($all) ? 0 : (max(array_keys($all)) + 1);
        foreach ($extraParams as $extraIdx => $v) {
            $all[$startIdx + $extraIdx] = [$v, ParamType::Auto];
        }

        $stmt = $this->stmt;
        $pdoParams = [];
        try {
            // Apply via bindValue en PDO (1-indexed) o con execute() directo.
            if ($all === []) {
                $ok = $stmt->execute();
            } else {
                // Si todos son Auto/Str/Null pasamos execute(array_values -> 1st value).
                $allAuto = true;
                $ordered = [];
                ksort($all, SORT_NUMERIC);
                foreach ($all as $idx => [$val, $type]) {
                    $ordered[$idx] = [$val, $type];
                    if ($type !== ParamType::Auto && $type !== ParamType::Str && $type !== ParamType::Null && $type !== ParamType::Int) {
                        $allAuto = false;
                    }
                }
                if ($allAuto) {
                    $values = [];
                    foreach ($ordered as [$val,]) {
                        $values[] = $val;
                    }
                    $ok = $stmt->execute($values);
                } else {
                    ksort($ordered, SORT_NUMERIC);
                    $pos = 1;
                    $max = 0;
                    foreach ($ordered as $idx => [$val, $type]) {
                        $max = max($max, $idx + 1);
                    }
                    // Fill con nulls en gaps.
                    $flatten = [];
                    for ($i = 0; $i < $max; $i++) {
                        $flatten[$i] = isset($ordered[$i]) ? $ordered[$i] : [null, ParamType::Null];
                    }
                    foreach ($flatten as [$val, $type]) {
                        $pdoType = self::mapToPdoParam($type, $val);
                        $stmt->bindValue($pos++, $val, $pdoType);
                    }
                    $ok = $stmt->execute();
                }
            }
        } catch (\Throwable $t) {
            throw $this->mapper->map($t, $this->owner, 'stmt.execute', $this->sql);
        }

        $this->owner->touchLastUsed();

        if (!$ok) {
            $err = $stmt->errorInfo();
            $msg = is_array($err) ? implode(' | ', $err) : 'unknown';
            $fake = new \RuntimeException($msg);
            throw $this->mapper->map($fake, $this->owner, 'stmt.execute_false', $this->sql);
        }

        return $this->buildQueryResult($stmt);
    }

    public function closeCursor(): void
    {
        try {
            $this->stmt->closeCursor();
        } catch (\Throwable) { /* ignore */
        }
        $this->owner->untrackPdoStatement($this->stmt);
    }

    private static function mapToPdoParam(ParamType $t, mixed $val): int
    {
        return match ($t) {
            ParamType::Auto, ParamType::Str => \PDO::PARAM_STR,
            ParamType::Null => \PDO::PARAM_NULL,
            ParamType::Int  => \PDO::PARAM_INT,
            ParamType::Bool => \PDO::PARAM_BOOL,
            ParamType::LOB  => \PDO::PARAM_LOB,
        };
    }

    private function buildQueryResult(\PDOStatement $stmt): QueryResult
    {
        $colCount = $stmt->columnCount();
        $isSelect = $colCount > 0;
        $affected = $stmt->rowCount();

        $meta = [];
        for ($i = 0; $i < $colCount; $i++) {
            try {
                $m = @$stmt->getColumnMeta($i);
                if (!is_array($m)) {
                    $meta[] = new ColumnMeta($i, "c{$i}", 'unknown');
                    continue;
                }
                $meta[] = new ColumnMeta(
                    ordinal: $i,
                    name: (string)($m['name'] ?? "c{$i}"),
                    nativeType: (string)($m['native_type'] ?? 'unknown'),
                    maxLength: (int)($m['len'] ?? -1),
                    isNullable: !isset($m['flags']) || !in_array('not_null', (array)$m['flags'], true),
                );
            } catch (\Throwable) {
                $meta[] = new ColumnMeta($i, "c{$i}", 'unknown');
            }
        }

        $cleanup = function () use ($stmt): void {
            try {
                $stmt->closeCursor();
            } catch (\Throwable) { /* ignore */
            }
            $this->owner->untrackPdoStatement($stmt);
        };

        $rowGenerator = static function () use ($stmt): \Generator {
            try {
                while (false !== ($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                    yield $row;
                }
            } catch (\Throwable) { /* ignore */
            }
        };

        $qr = new QueryResult(
            isSelect: $isSelect,
            affectedRows: $affected,
            columnMeta: $meta,
            rowGenerator: $rowGenerator(...),
            cleanup: $cleanup(...),
            columnCount: $colCount,
        );

        if (!$isSelect) {
            // Non-select statements: close cursor immediately so tx/savepoint
            // control does not see "SQL statements in progress" (SQLite, etc).
            $cleanup();
        }
        return $qr;
    }
}