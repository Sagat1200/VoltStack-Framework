<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use Quantum\Exceptions\Catalog\SemanticCatalogEntry;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;

final readonly class ExceptionCatalogReport
{
    /**
     * @param list<SemanticCatalogEntry> $entries
     * @param list<string> $ignoredCodes
     */
    public function __construct(
        private ExceptionCompilationPlan $plan,
        private array $entries,
        private array $ignoredCodes,
    ) {
    }

    public function plan(): ExceptionCompilationPlan
    {
        return $this->plan;
    }

    /**
     * @return list<SemanticCatalogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function ignoredCodes(): array
    {
        return $this->ignoredCodes;
    }

    public function totalEntries(): int
    {
        return count($this->entries);
    }

    public function ignoredEntryCount(): int
    {
        return count($this->ignoredCodes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'effective' => [
                'environment' => $this->plan->environment(),
                'runtime' => $this->plan->runtime(),
                'debug' => $this->plan->debug(),
                'fingerprint' => $this->plan->fingerprint(),
                'policy_revision' => $this->plan->policyRevision(),
                'php_runtime_version' => $this->plan->phpRuntimeVersion(),
                'reporters' => $this->plan->reporterIds(),
                'spa_versions' => $this->plan->spaVersions(),
            ],
            'summary' => [
                'catalog_entries' => $this->totalEntries(),
                'ignored_by_reporting' => $this->ignoredEntryCount(),
            ],
            'ignored_codes' => $this->ignoredCodes,
            'entries' => array_map(
                fn (SemanticCatalogEntry $entry): array => $this->describeEntry($entry),
                $this->entries,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeEntry(SemanticCatalogEntry $entry): array
    {
        return [
            'code' => $entry->code,
            'category' => $entry->category->value,
            'message_key' => $entry->messageKey,
            'severity' => $entry->severity->value,
            'effect' => $entry->effect->value,
            'retry_advice' => $entry->retryAdvice->value,
            'public_default' => $entry->publicDefault,
            'report_class' => $entry->reportClass,
            'http_default' => $entry->httpDefault,
            'cli_default' => $entry->cliDefault,
            'safe_parameter_keys' => $entry->safeParameterKeys,
            'ignored_by_reporting' => in_array($entry->code, $this->ignoredCodes, true),
        ];
    }
}
