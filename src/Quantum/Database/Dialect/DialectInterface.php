<?php declare(strict_types=1);

namespace Quantum\Database\Dialect;

use Quantum\Database\Dialect\Value\CompiledSql;
use Quantum\Database\Operation\DatabaseOperationInterface;

/**
 * Compila operaciones portátiles → SQL + params.
 * V1 implementa minimum viables: RawOperation compile bypass + ORM ops básicas.
 * La compilación de SQG completa (SQG → SQL) se entrega en Fase 2.
 */
interface DialectInterface
{
    /** Driver human name: 'pgsql' | 'mysql' | 'mariadb' | 'sqlite' */
    public function name(): string;

    /**
     * @param non-empty-string $identifier 1..3 partes (schema.table.column)
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Placeholder para N-ésimo parámetro (según paramStyle).
     * @param int $index 0-based
     */
    public function parameterPlaceholder(int $index): string;

    public function quoteStyle(): string;   // 'double' | 'backtick'
    public function paramStyle(): string;   // 'positional_q' | 'positional_$n' | 'named_colon'

    /**
     * Compila una operación cualquiera.
     * V1: RawOperation se emite con normalización de placeholders.
     * SQGOperation se emite con placeholder error (F2-required) → lanzada Capability Ex.
     */
    public function compile(DatabaseOperationInterface $op): CompiledSql;

    /**
     * Normaliza placeholders: recibe SQL con '?' y devuelve el estilo del dialecto (?, $1, :p1).
     * @param string $sqlRaw
     * @return array{sql:string, count:int}
     */
    public function normalizePlaceholders(string $sqlRaw): array;
}
