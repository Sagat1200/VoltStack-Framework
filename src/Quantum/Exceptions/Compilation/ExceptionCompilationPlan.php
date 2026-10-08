<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Compilation;

final readonly class ExceptionCompilationPlan
{
    /**
     * @param array<string, mixed> $config
     * @param list<string> $catalogCodes
     * @param list<string> $reporterIds
     * @param list<int> $spaVersions
     */
    public function __construct(
        private array $config,
        private string $fingerprint,
        private array $catalogCodes,
        private array $reporterIds,
        private array $spaVersions,
        private string $phpRuntimeVersion,
    ) {
        if ($this->fingerprint === '') {
            throw new ExceptionCompilationException('Exception compilation plan fingerprint must not be empty.');
        }

        if ($this->phpRuntimeVersion === '') {
            throw new ExceptionCompilationException('Exception compilation plan PHP runtime version must not be empty.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function policyRevision(): string
    {
        return $this->fingerprint;
    }

    /**
     * @return list<string>
     */
    public function catalogCodes(): array
    {
        return $this->catalogCodes;
    }

    /**
     * @return list<string>
     */
    public function reporterIds(): array
    {
        return $this->reporterIds;
    }

    /**
     * @return list<int>
     */
    public function spaVersions(): array
    {
        return $this->spaVersions;
    }

    public function phpRuntimeVersion(): string
    {
        return $this->phpRuntimeVersion;
    }

    public function debug(): bool
    {
        return $this->config['debug'] === true;
    }

    public function environment(): string
    {
        return (string) $this->config['environment'];
    }

    public function runtime(): string
    {
        return (string) $this->config['runtime'];
    }

    public function assertCompatibleWith(string $expectedRuntime, string $expectedPhpRuntimeVersion = PHP_VERSION): void
    {
        $schemaVersion = $this->config['schema_version'] ?? null;

        if ($schemaVersion !== 1) {
            throw new ExceptionCompilationException(sprintf(
                'Published exception compilation plan schema version [%s] is incompatible; expected [1].',
                is_scalar($schemaVersion) ? (string) $schemaVersion : get_debug_type($schemaVersion),
            ));
        }

        $runtime = trim($this->runtime());
        $expectedRuntime = trim($expectedRuntime);

        if ($runtime === '' || $runtime !== $expectedRuntime) {
            throw new ExceptionCompilationException(sprintf(
                'Published exception compilation plan runtime [%s] is incompatible with configured runtime [%s].',
                $runtime === '' ? '<empty>' : $runtime,
                $expectedRuntime === '' ? '<empty>' : $expectedRuntime,
            ));
        }

        if ($this->phpRuntimeVersion !== $expectedPhpRuntimeVersion) {
            throw new ExceptionCompilationException(sprintf(
                'Published exception compilation plan PHP runtime version [%s] is incompatible with current PHP version [%s].',
                $this->phpRuntimeVersion,
                $expectedPhpRuntimeVersion,
            ));
        }
    }

    /**
     * @return array{
     *     schema_version:int,
     *     fingerprint:string,
     *     policy_revision:string,
     *     php_runtime_version:string,
     *     catalog_codes:list<string>,
     *     reporters:list<string>,
     *     spa_versions:list<int>,
     *     config:array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'fingerprint' => $this->fingerprint,
            'policy_revision' => $this->policyRevision(),
            'php_runtime_version' => $this->phpRuntimeVersion,
            'catalog_codes' => $this->catalogCodes,
            'reporters' => $this->reporterIds,
            'spa_versions' => $this->spaVersions,
            'config' => $this->config,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $config = $payload['config'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        $policyRevision = $payload['policy_revision'] ?? null;
        $catalogCodes = $payload['catalog_codes'] ?? null;
        $reporters = $payload['reporters'] ?? null;
        $spaVersions = $payload['spa_versions'] ?? null;
        $phpRuntimeVersion = $payload['php_runtime_version'] ?? null;

        if ($schemaVersion !== 1
            || ! is_array($config)
            || ! is_string($fingerprint)
            || $fingerprint === ''
            || ! is_string($policyRevision)
            || $policyRevision === ''
            || $policyRevision !== $fingerprint
            || ! is_array($catalogCodes)
            || ! is_array($reporters)
            || ! is_array($spaVersions)
            || ! is_string($phpRuntimeVersion)
            || $phpRuntimeVersion === '') {
            throw new ExceptionCompilationException('The exception compilation artifact payload is invalid.');
        }

        return new self(
            config: $config,
            fingerprint: $fingerprint,
            catalogCodes: array_values(array_filter($catalogCodes, static fn(mixed $value): bool => is_string($value))),
            reporterIds: array_values(array_filter($reporters, static fn(mixed $value): bool => is_string($value))),
            spaVersions: array_values(array_map(static fn(mixed $value): int => (int) $value, $spaVersions)),
            phpRuntimeVersion: $phpRuntimeVersion,
        );
    }
}
