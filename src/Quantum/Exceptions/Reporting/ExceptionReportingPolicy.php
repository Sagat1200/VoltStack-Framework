<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportRecord;

final readonly class ExceptionReportingPolicy
{
    /**
     * @param list<string> $ignoredCodes
     * @param list<SemanticCategory> $ignoredCategories
     * @param array<string, float> $sampleRatesByCode
     * @param array<string, float> $sampleRatesByReporter
     * @param list<string> $disabledReporters
     */
    public function __construct(
        public string $revision = 'v1',
        public array $ignoredCodes = [],
        public array $ignoredCategories = [],
        public ?SemanticSeverity $minimumSeverity = null,
        public float $sampleRate = 1.0,
        public array $sampleRatesByCode = [],
        public array $sampleRatesByReporter = [],
        public array $disabledReporters = [],
    ) {
        if ($revision === '') {
            throw new \InvalidArgumentException('ExceptionReportingPolicy revision must not be empty.');
        }

        $this->assertRate($sampleRate, 'sampleRate');

        foreach ($sampleRatesByCode as $code => $rate) {
            if (! is_string($code) || $code === '') {
                throw new \InvalidArgumentException('ExceptionReportingPolicy sampleRatesByCode keys must be non-empty strings.');
            }

            $this->assertRate($rate, sprintf('sampleRatesByCode[%s]', $code));
        }

        foreach ($sampleRatesByReporter as $reporterId => $rate) {
            if (! is_string($reporterId) || $reporterId === '') {
                throw new \InvalidArgumentException('ExceptionReportingPolicy sampleRatesByReporter keys must be non-empty strings.');
            }

            $this->assertRate($rate, sprintf('sampleRatesByReporter[%s]', $reporterId));
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function decide(
        ReportRecord $record,
        string $reporterId,
        ReportBudget $budget,
        int $attemptNumber,
        bool $budgetExpired,
    ): ReportingPolicyDecision {
        if ($budgetExpired) {
            return ReportingPolicyDecision::skip('budget_exhausted');
        }

        if (in_array($reporterId, $this->disabledReporters, true)) {
            return ReportingPolicyDecision::skip('policy_ignored');
        }

        if (in_array($record->semantic->code, $this->ignoredCodes, true)) {
            return ReportingPolicyDecision::skip('policy_ignored');
        }

        if (in_array($record->semantic->category, $this->ignoredCategories, true)) {
            return ReportingPolicyDecision::skip('policy_ignored');
        }

        if ($this->minimumSeverity !== null && $this->severityRank($record->semantic->severity) < $this->severityRank($this->minimumSeverity)) {
            return ReportingPolicyDecision::skip('policy_ignored');
        }

        $sampleRate = $this->sampleRatesByReporter[$reporterId]
            ?? $this->sampleRatesByCode[$record->semantic->code]
            ?? $this->sampleRate;

        if ($sampleRate <= 0.0) {
            return ReportingPolicyDecision::skip('sampled_out');
        }

        if ($sampleRate < 1.0 && ! $this->passesSample($record, $reporterId, $sampleRate)) {
            return ReportingPolicyDecision::skip('sampled_out');
        }

        return ReportingPolicyDecision::allow();
    }

    private function severityRank(SemanticSeverity $severity): int
    {
        return match ($severity) {
            SemanticSeverity::Debug => 10,
            SemanticSeverity::Info => 20,
            SemanticSeverity::Notice => 30,
            SemanticSeverity::Warning => 40,
            SemanticSeverity::Error => 50,
            SemanticSeverity::Critical => 60,
        };
    }

    private function passesSample(ReportRecord $record, string $reporterId, float $sampleRate): bool
    {
        $hash = sha1(sprintf('%s|%s|%s', $record->fingerprint, $record->occurrenceId, $reporterId));
        $sample = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;

        return $sample <= $sampleRate;
    }

    private function assertRate(float $rate, string $label): void
    {
        if ($rate < 0.0 || $rate > 1.0) {
            throw new \InvalidArgumentException(sprintf('ExceptionReportingPolicy %s must be between 0.0 and 1.0.', $label));
        }
    }
}
