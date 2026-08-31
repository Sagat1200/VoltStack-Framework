<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Dialect\Value\CompiledSql;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Operation\DatabaseOperationInterface;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\Orm\EntityInsertOperation;
use Quantum\Database\Operation\Orm\EntityUpdateOperation;
use Quantum\Database\Operation\Orm\EntityDeleteOperation;
use Quantum\Database\Operation\RawOperation;
use Quantum\Database\Operation\SqgOperation;
use Quantum\Database\Dialect\Emitter\NodeSqlEmitter;
use Quantum\Database\Operation\Sqg\GraphCertification;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Enum\DriverInfo;

/**
 * Clase base AbstractDialect con toda la lógica común: quoting / placeholders / compile Raw/ORM.
 * Las 4 dialects solo definen el estilo de quoting/placeholders + matriz de capacidades + NodeSqlEmitter factory.
 */
abstract class AbstractDialect implements DialectInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $quoteChar,
        private readonly string $paramStyle,
    ) {}

    public function name(): string { return $this->name; }
    public function quoteStyle(): string { return $this->quoteChar === '"' ? 'double' : 'backtick'; }
    public function paramStyle(): string { return $this->paramStyle; }

    final public function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new DbalException(DatabaseFailureKind::Validation, 'quote_identifier', message: 'Empty identifier');
        }
        $parts = explode('.', $identifier);
        $q = $this->quoteChar;
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                throw new DbalException(DatabaseFailureKind::Validation, 'quote_identifier', message: "Bad identifier: $identifier");
            }
            $out[] = $q . str_replace($q, $q . $q, $p) . $q;
        }
        return implode('.', $out);
    }

    final public function parameterPlaceholder(int $index): string
    {
        return match ($this->paramStyle) {
            'positional_$n' => '$' . ($index + 1),
            'named_colon'   => ':p' . $index,
            default         => '?',
        };
    }

    /** @inheritDoc */
    final public function normalizePlaceholders(string $sqlRaw): array
    {
        $count = substr_count($sqlRaw, '?');
        if ($this->paramStyle === 'positional_q') {
            return ['sql' => $sqlRaw, 'count' => $count];
        }
        if ($count === 0) return ['sql' => $sqlRaw, 'count' => 0];
        $i = 0;
        $out = preg_replace_callback('/\?/', function () use (&$i): string {
            return $this->parameterPlaceholder($i++);
        }, $sqlRaw);
        return ['sql' => (string)$out, 'count' => $count];
    }

    final public function compile(DatabaseOperationInterface $op,
                                  ?DatabaseCapabilitySet $caps = null): CompiledSql
    {
        return match (true) {
            $op instanceof RawOperation        => $this->compileRaw($op),
            $op instanceof EntityInsertOperation => $this->compileOrmInsert($op),
            $op instanceof EntityUpdateOperation => $this->compileOrmUpdate($op),
            $op instanceof EntityDeleteOperation => $this->compileOrmDelete($op),
            $op instanceof SqgOperation        => $this->compileSqg($op, $caps ?? DatabaseCapabilitySet::minimalSet()),
            default => throw new DbalException(
                DatabaseFailureKind::Validation,
                'compile.unknown',
                message: 'Unknown Operation type: ' . $op::class,
            ),
        };
    }

    private function compileRaw(RawOperation $op): CompiledSql
    {
        ['sql' => $sql, 'count' => $n] = $this->normalizePlaceholders($op->sql);
        return new CompiledSql(
            sql: $sql,
            params: array_values($op->params),
            paramCount: $n,
            fingerprint: CompiledSql::fingerprintFor($this->stripValues($sql)),
            quoteStyle: $this->quoteStyle(),
            paramStyle: $this->paramStyle(),
        );
    }

    private function compileOrmInsert(EntityInsertOperation $op): CompiledSql
    {
        $cols = array_keys($op->row);
        $q = fn($c) => $this->quoteIdentifier($c);
        $quotedCols = array_map($q, $cols);
        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($op->row as $v) {
            $placeholders[] = $this->parameterPlaceholder($i++);
            $params[] = $v;
        }
        $table = $this->quoteIdentifier($op->tableName);
        $sql = "INSERT INTO {$table} (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        if ($op->returning !== null && $this->supportsReturning()) {
            $sql .= ' RETURNING ' . implode(', ', array_map($q, $op->returning));
        }
        return new CompiledSql(
            sql: $sql, params: $params, paramCount: count($params),
            fingerprint: CompiledSql::fingerprintFor($this->stripValues($sql)),
            quoteStyle: $this->quoteStyle(), paramStyle: $this->paramStyle(),
        );
    }

    private function compileOrmUpdate(EntityUpdateOperation $op): CompiledSql
    {
        $set = [];
        $params = [];
        $i = 0;
        foreach ($op->changeSet as $col => $v) {
            $set[] = $this->quoteIdentifier($col) . ' = ' . $this->parameterPlaceholder($i++);
            $params[] = $v;
        }
        if ($set === []) {
            throw new DbalException(DatabaseFailureKind::Validation, 'compile.orm.update', message: 'Empty changeSet');
        }
        $where = [];
        foreach ($op->identifier as $col => $v) {
            $where[] = $this->quoteIdentifier($col) . ' = ' . $this->parameterPlaceholder($i++);
            $params[] = $v;
        }
        if ($op->expectedVersion !== null) {
            $where[] = $this->quoteIdentifier($op->versionColumn) . ' = ' . $this->parameterPlaceholder($i++);
            $params[] = $op->expectedVersion;
        }
        $table = $this->quoteIdentifier($op->tableName);
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $where);
        return new CompiledSql(
            sql: $sql, params: $params, paramCount: count($params),
            fingerprint: CompiledSql::fingerprintFor($this->stripValues($sql)),
            quoteStyle: $this->quoteStyle(), paramStyle: $this->paramStyle(),
        );
    }

    private function compileOrmDelete(EntityDeleteOperation $op): CompiledSql
    {
        $where = [];
        $params = [];
        $i = 0;
        foreach ($op->identifier as $col => $v) {
            $where[] = $this->quoteIdentifier($col) . ' = ' . $this->parameterPlaceholder($i++);
            $params[] = $v;
        }
        if ($op->expectedVersion !== null) {
            $where[] = $this->quoteIdentifier($op->versionColumn) . ' = ' . $this->parameterPlaceholder($i++);
            $params[] = $op->expectedVersion;
        }
        $table = $this->quoteIdentifier($op->tableName);
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $where);
        return new CompiledSql(
            sql: $sql, params: $params, paramCount: count($params),
            fingerprint: CompiledSql::fingerprintFor($this->stripValues($sql)),
            quoteStyle: $this->quoteStyle(), paramStyle: $this->paramStyle(),
        );
    }

    /**
     * Remueve constantes literales (por ahora sólo placeholder '?'/values de literales escapables) para fingerprint.
     * V1 versión simple: colapsa whitespaces + digits/strings quoted.
     */
    private function stripValues(string $sql): string
    {
        $s = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        // quita literales '' / "" / ? / $N / :p\d
        $s = (string)preg_replace("/'[^']*'/", "''", $s);
        $s = (string)preg_replace('/"[^"]*"/', '""', $s);
        $s = (string)preg_replace('/\b\d+(\.\d+)?\b/', '0', $s);
        return $s;
    }

    /** Override por dialect. */
    protected function supportsReturning(): bool { return false; }

    /**
     * @return class-string<NodeSqlEmitter>
     */
    protected function nodeSqlEmitterClass(): string
    {
        return NodeSqlEmitter::class;
    }

    private function compileSqg(SqgOperation $op, DatabaseCapabilitySet $caps): CompiledSql
    {
        $graph = $op->planArtifact?->graph ?? $op->graph();
        $cert = $graph->validate($caps);
        if (!$cert->valid) {
            $msgs = [];
            foreach ($cert->errors() as $e) $msgs[] = "[{$e->code}] {$e->message}";
            throw new DbalException(DatabaseFailureKind::Validation, 'compile.sqg.validate',
                message: 'SQG validation failed: ' . implode('; ', $msgs));
        }
        $class = $this->nodeSqlEmitterClass();
        $emitter = new $class($this);
        \assert($emitter instanceof NodeSqlEmitter);
        $compiled = $emitter->emit($graph->root, $graph->parameters);
        // Sobrescribimos fingerprint con la certificada del graph.
        return new CompiledSql(
            sql: $compiled->sql,
            params: $compiled->params,
            paramCount: $compiled->paramCount,
            fingerprint: $op->planArtifact?->fingerprint ?? $op->certificationFingerprint ?? $cert->fingerprint,
            quoteStyle: $this->quoteStyle(),
            paramStyle: $this->paramStyle(),
        );
    }
}
