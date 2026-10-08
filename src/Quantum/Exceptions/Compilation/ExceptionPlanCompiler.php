<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Compilation;

use Quantum\Config\Schema\Builtin\ExceptionsConfigSchema;
use Quantum\Config\Validation\ConfigValidator;
use Quantum\Exceptions\Catalog\SemanticErrorCatalog;

final class ExceptionPlanCompiler
{
    private const SUPPORTED_SPA_VERSIONS = [1];
    private const KNOWN_REPORTERS = [
        'exceptions.log',
        'exceptions.telemetry',
        'exceptions.events',
    ];

    /**
     * @param array<string, int> $limitMaximums
     */
    public function __construct(
        private readonly ?SemanticErrorCatalog $catalog = null,
        private readonly array $limitMaximums = [
            'handling_depth' => 4,
            'causes' => 16,
            'frames_per_cause' => 128,
            'message_bytes' => 8192,
            'snapshot_bytes' => 131072,
            'attributes' => 128,
            'attribute_depth' => 8,
            'occurrences_per_scope' => 1024,
            'mapping_candidates' => 256,
            'field_errors' => 100,
            'output_bytes' => 65536,
            'header_bytes' => 16384,
        ],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function compile(array $config): ExceptionCompilationPlan
    {
        $validation = (new ConfigValidator())->validate(ExceptionsConfigSchema::build(), $config);

        if (! $validation->isValid()) {
            throw new ExceptionCompilationException($this->formatViolations($validation->violations()));
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $validation->data();
        $catalog = $this->catalog();

        $this->assertSchemaVersion($normalized);
        $this->assertLocales($normalized);
        $this->assertProductionDebugGate($normalized);
        $this->assertPositiveInteger('exceptions.reporting.sync_budget_ms', $normalized['reporting']['sync_budget_ms'] ?? null);
        $this->assertPositiveInteger('exceptions.reporting.buffer_records', $normalized['reporting']['buffer_records'] ?? null);
        $this->assertPositiveInteger('exceptions.reporting.buffer_bytes', $normalized['reporting']['buffer_bytes'] ?? null);
        $this->assertPositiveInteger('exceptions.reporting.buffer_ttl_seconds', $normalized['reporting']['buffer_ttl_seconds'] ?? null);
        $this->assertPositiveInteger('exceptions.privacy.diagnostic_retention_days', $normalized['privacy']['diagnostic_retention_days'] ?? null);
        $this->assertPositiveInteger('exceptions.privacy.aggregate_retention_days', $normalized['privacy']['aggregate_retention_days'] ?? null);
        $this->assertValidSampleRate($normalized['reporting']['sample_rate'] ?? null);
        $this->assertAutomaticReplayDisabled($normalized);
        $this->assertIgnoreCodes($normalized, $catalog);
        $this->assertReporterIds($normalized);
        $this->assertSpaVersions($normalized);
        $this->assertRules($normalized);
        $this->assertLimits($normalized);
        $normalized = $this->canonicalize($normalized);

        $fingerprint = sha1(json_encode([
            'schema_version' => $normalized['schema_version'],
            'environment' => $normalized['environment'],
            'debug' => $normalized['debug'],
            'default_locale' => $normalized['default_locale'],
            'fallback_locale' => $normalized['fallback_locale'],
            'runtime' => $normalized['runtime'],
            'limits' => $normalized['limits'],
            'reporting' => $normalized['reporting'],
            'rendering' => $normalized['rendering'],
            'recovery' => $normalized['recovery'],
            'privacy' => $normalized['privacy'],
            'compilation' => $normalized['compilation'],
            'rules' => $normalized['rules'],
            'catalog_codes' => array_keys($catalog->all()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return new ExceptionCompilationPlan(
            config: $normalized,
            fingerprint: $fingerprint,
            catalogCodes: array_keys($catalog->all()),
            reporterIds: array_values($normalized['reporting']['reporters'] ?? []),
            spaVersions: array_values($normalized['rendering']['spa_versions'] ?? []),
            phpRuntimeVersion: PHP_VERSION,
        );
    }

    private function catalog(): SemanticErrorCatalog
    {
        return $this->catalog ?? SemanticErrorCatalog::defaults();
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertSchemaVersion(array $normalized): void
    {
        if (($normalized['schema_version'] ?? null) !== 1) {
            throw new ExceptionCompilationException('Configuration path [exceptions.schema_version] must be exactly 1.');
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertLocales(array $normalized): void
    {
        foreach (['default_locale', 'fallback_locale', 'runtime'] as $key) {
            $value = trim((string) ($normalized[$key] ?? ''));

            if ($value === '') {
                throw new ExceptionCompilationException(sprintf('Configuration path [exceptions.%s] must not be empty.', $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertProductionDebugGate(array $normalized): void
    {
        if (($normalized['environment'] ?? null) === 'production' && ($normalized['debug'] ?? false) === true) {
            throw new ExceptionCompilationException('Configuration path [exceptions.debug] cannot be true in production.');
        }
    }

    private function assertPositiveInteger(string $path, mixed $value): void
    {
        if (! is_int($value) || $value <= 0) {
            throw new ExceptionCompilationException(sprintf('Configuration path [%s] must be a positive integer.', $path));
        }
    }

    private function assertValidSampleRate(mixed $value): void
    {
        if (! is_float($value) && ! is_int($value)) {
            throw new ExceptionCompilationException('Configuration path [exceptions.reporting.sample_rate] must be a float between 0.0 and 1.0.');
        }

        $rate = (float) $value;

        if ($rate < 0.0 || $rate > 1.0) {
            throw new ExceptionCompilationException('Configuration path [exceptions.reporting.sample_rate] must be between 0.0 and 1.0.');
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertAutomaticReplayDisabled(array $normalized): void
    {
        if (($normalized['recovery']['automatic_replay'] ?? false) !== false) {
            throw new ExceptionCompilationException('Configuration path [exceptions.recovery.automatic_replay] must remain false in v1.');
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertIgnoreCodes(array $normalized, SemanticErrorCatalog $catalog): void
    {
        $ignoreCodes = $normalized['reporting']['ignore_codes'] ?? [];

        if (! is_array($ignoreCodes)) {
            throw new ExceptionCompilationException('Configuration path [exceptions.reporting.ignore_codes] must be a list.');
        }

        foreach ($ignoreCodes as $index => $code) {
            if (! is_string($code) || $code === '' || $catalog->get($code) === null) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.reporting.ignore_codes.%d] must reference a registered semantic code.',
                    $index,
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertReporterIds(array $normalized): void
    {
        $reporters = $normalized['reporting']['reporters'] ?? [];

        if (! is_array($reporters) || $reporters === []) {
            throw new ExceptionCompilationException('Configuration path [exceptions.reporting.reporters] must contain at least one reporter id.');
        }

        $seen = [];

        foreach ($reporters as $index => $reporterId) {
            if (! is_string($reporterId) || $reporterId === '') {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.reporting.reporters.%d] must be a non-empty string.',
                    $index,
                ));
            }

            if (! in_array($reporterId, self::KNOWN_REPORTERS, true)) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.reporting.reporters.%d] references unknown reporter [%s].',
                    $index,
                    $reporterId,
                ));
            }

            if (isset($seen[$reporterId])) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.reporting.reporters.%d] duplicates reporter [%s].',
                    $index,
                    $reporterId,
                ));
            }

            $seen[$reporterId] = true;
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertSpaVersions(array $normalized): void
    {
        $versions = $normalized['rendering']['spa_versions'] ?? [];

        if (! is_array($versions) || $versions === []) {
            throw new ExceptionCompilationException('Configuration path [exceptions.rendering.spa_versions] must not be empty.');
        }

        $seen = [];

        foreach ($versions as $index => $version) {
            if (! is_int($version) || ! in_array($version, self::SUPPORTED_SPA_VERSIONS, true)) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rendering.spa_versions.%d] must reference a supported SPA protocol version.',
                    $index,
                ));
            }

            if (isset($seen[$version])) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rendering.spa_versions.%d] duplicates version [%d].',
                    $index,
                    $version,
                ));
            }

            $seen[$version] = true;
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertRules(array $normalized): void
    {
        $rules = $normalized['rules'] ?? [];

        if (! is_array($rules)) {
            throw new ExceptionCompilationException('Configuration path [exceptions.rules] must be a list.');
        }

        $seen = [];

        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                throw new ExceptionCompilationException(sprintf('Configuration path [exceptions.rules.%d] must be a map.', $index));
            }

            $id = trim((string) ($rule['id'] ?? ''));
            $exceptionType = trim((string) ($rule['exceptionType'] ?? ''));
            $serviceId = trim((string) ($rule['serviceId'] ?? ''));
            $priority = $rule['priority'] ?? null;
            $predicates = $rule['predicates'] ?? [];

            if ($id === '' || $exceptionType === '' || $serviceId === '') {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rules.%d] requires non-empty id, exceptionType and serviceId.',
                    $index,
                ));
            }

            if (! is_int($priority) || $priority < -1000 || $priority > 1000) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rules.%d.priority] must be between -1000 and 1000.',
                    $index,
                ));
            }

            if (! is_array($predicates)) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rules.%d.predicates] must be a list.',
                    $index,
                ));
            }

            foreach ($predicates as $predicateIndex => $predicate) {
                if (! is_string($predicate) || trim($predicate) === '') {
                    throw new ExceptionCompilationException(sprintf(
                        'Configuration path [exceptions.rules.%d.predicates.%d] must be a non-empty string.',
                        $index,
                        $predicateIndex,
                    ));
                }
            }

            if (isset($seen[$id])) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.rules.%d.id] duplicates rule id [%s].',
                    $index,
                    $id,
                ));
            }

            $seen[$id] = true;
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertLimits(array $normalized): void
    {
        $limits = $normalized['limits'] ?? [];

        if (! is_array($limits)) {
            throw new ExceptionCompilationException('Configuration path [exceptions.limits] must be a map.');
        }

        foreach ($this->limitMaximums as $key => $maximum) {
            $value = $limits[$key] ?? null;

            if (! is_int($value) || $value <= 0) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.limits.%s] must be a positive integer.',
                    $key,
                ));
            }

            if ($value > $maximum) {
                throw new ExceptionCompilationException(sprintf(
                    'Configuration path [exceptions.limits.%s] exceeds the v1 provider maximum of %d.',
                    $key,
                    $maximum,
                ));
            }
        }
    }

    /**
     * @param list<\Quantum\Config\Validation\ConfigViolation> $violations
     */
    private function formatViolations(array $violations): string
    {
        $messages = array_map(
            static function (\Quantum\Config\Validation\ConfigViolation $violation): string {
                if ($violation->code() === 'unknown_key') {
                    return sprintf('Unknown exception configuration key [%s].', $violation->path());
                }

                return $violation->message();
            },
            $violations,
        );

        return implode(' ', $messages);
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private function canonicalize(array $normalized): array
    {
        $reporters = $normalized['reporting']['reporters'] ?? [];
        $ignoreCodes = $normalized['reporting']['ignore_codes'] ?? [];
        $spaVersions = $normalized['rendering']['spa_versions'] ?? [];
        $rules = $normalized['rules'] ?? [];

        if (is_array($reporters)) {
            $reporters = array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), $reporters),
                static fn (string $value): bool => $value !== '',
            )));
            sort($reporters);
            $normalized['reporting']['reporters'] = $reporters;
        }

        if (is_array($ignoreCodes)) {
            $ignoreCodes = array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), $ignoreCodes),
                static fn (string $value): bool => $value !== '',
            )));
            sort($ignoreCodes);
            $normalized['reporting']['ignore_codes'] = $ignoreCodes;
        }

        if (is_array($spaVersions)) {
            $spaVersions = array_values(array_unique(array_map(static fn (mixed $value): int => (int) $value, $spaVersions)));
            sort($spaVersions);
            $normalized['rendering']['spa_versions'] = $spaVersions;
        }

        if (is_array($rules)) {
            usort($rules, static function (array $left, array $right): int {
                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            });

            $normalized['rules'] = array_map(static function (array $rule): array {
                $predicates = $rule['predicates'] ?? [];

                if (is_array($predicates)) {
                    $predicates = array_values(array_unique(array_filter(
                        array_map(static fn (mixed $value): string => trim((string) $value), $predicates),
                        static fn (string $value): bool => $value !== '',
                    )));
                    sort($predicates);
                    $rule['predicates'] = $predicates;
                }

                return $rule;
            }, $rules);
        }

        return $normalized;
    }
}
