<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Catalog;

use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;

final class SemanticErrorCatalog
{
    /**
     * @var array<string, SemanticCatalogEntry>
     */
    private array $entries = [];

    /**
     * @param iterable<SemanticCatalogEntry> $entries
     */
    public function __construct(iterable $entries = [])
    {
        foreach ($entries as $entry) {
            $this->register($entry);
        }
    }

    public static function defaults(): self
    {
        return new self([
            new SemanticCatalogEntry(
                code: 'validation.failed',
                category: SemanticCategory::Validation,
                messageKey: 'exceptions.validation.failed',
                severity: SemanticSeverity::Notice,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 422,
                cliDefault: 2,
                safeParameterKeys: ['fields'],
            ),
            new SemanticCatalogEntry(
                code: 'authentication.required',
                category: SemanticCategory::Authentication,
                messageKey: 'exceptions.authentication.required',
                severity: SemanticSeverity::Info,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 401,
                cliDefault: 3,
            ),
            new SemanticCatalogEntry(
                code: 'authentication.failed',
                category: SemanticCategory::Authentication,
                messageKey: 'exceptions.authentication.failed',
                severity: SemanticSeverity::Warning,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 401,
                cliDefault: 3,
                safeParameterKeys: ['reason_code'],
            ),
            new SemanticCatalogEntry(
                code: 'authorization.denied',
                category: SemanticCategory::Authorization,
                messageKey: 'exceptions.authorization.denied',
                severity: SemanticSeverity::Warning,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 403,
                cliDefault: 3,
            ),
            new SemanticCatalogEntry(
                code: 'authorization.challenge',
                category: SemanticCategory::Authorization,
                messageKey: 'exceptions.authorization.challenge',
                severity: SemanticSeverity::Info,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 403,
                cliDefault: 3,
            ),
            new SemanticCatalogEntry(
                code: 'resource.not_found',
                category: SemanticCategory::NotFound,
                messageKey: 'exceptions.resource.not_found',
                severity: SemanticSeverity::Info,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 404,
                cliDefault: 4,
            ),
            new SemanticCatalogEntry(
                code: 'resource.conflict',
                category: SemanticCategory::Conflict,
                messageKey: 'exceptions.resource.conflict',
                severity: SemanticSeverity::Info,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::Conditional,
                httpDefault: 409,
                cliDefault: 5,
            ),
            new SemanticCatalogEntry(
                code: 'request.throttled',
                category: SemanticCategory::Throttled,
                messageKey: 'exceptions.request.throttled',
                severity: SemanticSeverity::Warning,
                effect: Effect::None,
                retryAdvice: RetryAdvice::Conditional,
                httpDefault: 429,
                cliDefault: 6,
                safeParameterKeys: ['retry_after_seconds', 'reason_code'],
            ),
            new SemanticCatalogEntry(
                code: 'dependency.unavailable',
                category: SemanticCategory::Dependency,
                messageKey: 'exceptions.dependency.unavailable',
                severity: SemanticSeverity::Error,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::Conditional,
                httpDefault: 503,
                cliDefault: 6,
                safeParameterKeys: ['phase'],
            ),
            new SemanticCatalogEntry(
                code: 'operation.indeterminate',
                category: SemanticCategory::Internal,
                messageKey: 'exceptions.operation.indeterminate',
                severity: SemanticSeverity::Error,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::ReconcileFirst,
                httpDefault: 503,
                cliDefault: 6,
            ),
            new SemanticCatalogEntry(
                code: 'operation.cancelled',
                category: SemanticCategory::Cancelled,
                messageKey: 'exceptions.operation.cancelled',
                severity: SemanticSeverity::Info,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::Never,
                cliDefault: 130,
            ),
            new SemanticCatalogEntry(
                code: 'configuration.invalid',
                category: SemanticCategory::Configuration,
                messageKey: 'exceptions.configuration.invalid',
                severity: SemanticSeverity::Critical,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 500,
                cliDefault: 78,
            ),
            new SemanticCatalogEntry(
                code: 'internal.error',
                category: SemanticCategory::Internal,
                messageKey: 'exceptions.internal.error',
                severity: SemanticSeverity::Error,
                effect: Effect::Unknown,
                retryAdvice: RetryAdvice::Never,
                httpDefault: 500,
                cliDefault: 1,
            ),
        ]);
    }

    public function register(SemanticCatalogEntry $entry): void
    {
        $this->entries[$entry->code] = $entry;
    }

    public function get(string $code): ?SemanticCatalogEntry
    {
        return $this->entries[$code] ?? null;
    }

    public function require(string $code): SemanticCatalogEntry
    {
        $entry = $this->get($code);

        if ($entry === null) {
            throw new \LogicException(sprintf('Semantic catalog entry [%s] is not registered.', $code));
        }

        return $entry;
    }

    /**
     * @return array<string, SemanticCatalogEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
