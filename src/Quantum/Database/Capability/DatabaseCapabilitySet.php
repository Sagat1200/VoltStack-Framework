<?php

declare(strict_types=1);

namespace Quantum\Database\Capability;

/**
 * Set de capacidades concreto (V1). 14 flags tipadas + extra extensibles.
 * Ver DDD-V1-10 matriz por motor.
 */
final readonly class DatabaseCapabilitySet
{
    /**
     * @param array<string,scalar> $extra
     */
    public function __construct(
        public bool $returningClause,
        public bool $upsertOnConflict,
        public bool $upsertOnDuplicateKey,
        public bool $savepoints,
        public bool $cteRecursive,
        public bool $windowFunctions,
        public bool $jsonb,
        public bool $arrayTypes,
        public bool $uuidNative,
        public bool $temporalTemporal,
        public bool $multipleActiveResultSets,
        public bool $batchedInserts,
        public bool $triggersPerRow,
        public string $quoteStyle,      // 'double' o 'backtick'
        public string $paramStyle,      // 'positional_q' 'positional_$n' 'named_colon'
        public array $extra = [],
    ) {}

    public function supportsReturning(): bool
    {
        return $this->returningClause;
    }
    public function supportsSavepoints(): bool
    {
        return $this->savepoints;
    }

    public function supports(string $flag): bool
    {
        if (property_exists($this, $flag)) {
            return (bool)$this->$flag;
        }
        return (bool)($this->extra[$flag] ?? false);
    }

    /**
     * Auto-detección por driver + serverVersion (fallback rápido si el driver aún no reporta capabilities).
     */
    public static function detectFromDriverInfo(string $driverName, string $serverVersion): self
    {
        $v = $serverVersion;
        $gt = static fn(string $need): bool => version_compare($v, $need, '>=');

        return match (strtolower($driverName)) {
            'pgsql' => new self(
                returningClause: true,
                upsertOnConflict: $gt('9.5'),
                upsertOnDuplicateKey: false,
                savepoints: true,
                cteRecursive: $gt('8.4'),
                windowFunctions: true,
                jsonb: $gt('9.4'),
                arrayTypes: true,
                uuidNative: $gt('13'),
                temporalTemporal: false,
                multipleActiveResultSets: false,
                batchedInserts: true,
                triggersPerRow: true,
                quoteStyle: 'double',
                paramStyle: 'positional_$n',
            ),
            'mysql' => new self(
                returningClause: false,
                upsertOnConflict: false,
                upsertOnDuplicateKey: true,
                savepoints: true,
                cteRecursive: $gt('8.0'),
                windowFunctions: $gt('8.0'),
                jsonb: $gt('5.7'),
                arrayTypes: false,
                uuidNative: false,
                temporalTemporal: false,
                multipleActiveResultSets: false,
                batchedInserts: true,
                triggersPerRow: true,
                quoteStyle: 'backtick',
                paramStyle: 'positional_q',
            ),
            'mariadb' => new self(
                returningClause: $gt('10.5'),
                upsertOnConflict: false,
                upsertOnDuplicateKey: true,
                savepoints: true,
                cteRecursive: $gt('10.2'),
                windowFunctions: $gt('10.2'),
                jsonb: $gt('10.2'),
                arrayTypes: false,
                uuidNative: false,
                temporalTemporal: false,
                multipleActiveResultSets: false,
                batchedInserts: true,
                triggersPerRow: true,
                quoteStyle: 'backtick',
                paramStyle: 'positional_q',
            ),
            'sqlite' => new self(
                returningClause: $gt('3.35'),
                upsertOnConflict: $gt('3.24'),
                upsertOnDuplicateKey: false,
                savepoints: $gt('3.6.8'),
                cteRecursive: $gt('3.8.3'),
                windowFunctions: $gt('3.25'),
                jsonb: $gt('3.38'),
                arrayTypes: false,
                uuidNative: false,
                temporalTemporal: false,
                multipleActiveResultSets: true,
                batchedInserts: true,
                triggersPerRow: true,
                quoteStyle: 'double',
                paramStyle: 'positional_q',
            ),
            default => self::minimalSet($driverName),
        };
    }

    private static function minimalSet(string $driverName): self
    {
        return new self(
            returningClause: false,
            upsertOnConflict: false,
            upsertOnDuplicateKey: false,
            savepoints: false,
            cteRecursive: false,
            windowFunctions: false,
            jsonb: false,
            arrayTypes: false,
            uuidNative: false,
            temporalTemporal: false,
            multipleActiveResultSets: false,
            batchedInserts: true,
            triggersPerRow: false,
            quoteStyle: 'double',
            paramStyle: 'positional_q',
            extra: ['unsupported_driver' => $driverName],
        );
    }
}