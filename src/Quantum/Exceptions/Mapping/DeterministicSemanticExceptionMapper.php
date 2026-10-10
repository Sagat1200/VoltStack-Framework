<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Mapping;

use Quantum\Exceptions\Catalog\SemanticErrorCatalog;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;
use Throwable;

final class DeterministicSemanticExceptionMapper implements SemanticExceptionMapperInterface
{
    /**
     * @var list<MappingRule>
     */
    private array $rules;

    /**
     * @param iterable<MappingRule> $rules
     */
    public function __construct(
        private readonly SemanticErrorCatalog $catalog,
        iterable $rules = [],
    ) {
        $this->rules = array_values(is_array($rules) ? $rules : iterator_to_array($rules, false));
    }

    public static function standard(?SemanticErrorCatalog $catalog = null): self
    {
        $catalog ??= SemanticErrorCatalog::defaults();

        return new self($catalog, [
            new MappingRule(
                id: 'auth.required',
                exceptionType: \Quantum\Auth\Exceptions\AuthenticationRequiredException::class,
                catalogCode: 'authentication.required',
                priority: 200,
            ),
            new MappingRule(
                id: 'auth.throttled',
                exceptionType: \Quantum\Auth\Exceptions\ThrottleDeniedException::class,
                catalogCode: 'request.throttled',
                priority: 220,
                parameterFactory: static fn (FailureSnapshot $failure, ExceptionContext $context): array => [
                    'retry_after_seconds' => is_int($failure->safeMetadata['retry_after_seconds'] ?? null)
                        ? $failure->safeMetadata['retry_after_seconds']
                        : null,
                    'reason_code' => is_string($failure->safeMetadata['auth_reason_code'] ?? null)
                        ? $failure->safeMetadata['auth_reason_code']
                        : null,
                ],
            ),
            new MappingRule(
                id: 'auth.generic',
                exceptionType: \Quantum\Auth\Exceptions\AuthenticationException::class,
                catalogCode: 'authentication.failed',
                priority: 120,
                parameterFactory: static fn (FailureSnapshot $failure, ExceptionContext $context): array => [
                    'reason_code' => is_string($failure->safeMetadata['auth_reason_code'] ?? null)
                        ? $failure->safeMetadata['auth_reason_code']
                        : null,
                ],
            ),
            new MappingRule(
                id: 'validation.failed',
                exceptionType: \Quantum\Validation\Exceptions\ValidationException::class,
                catalogCode: 'validation.failed',
                priority: 210,
                parameterFactory: static fn (FailureSnapshot $failure, ExceptionContext $context): array => [
                    'fields' => is_array($failure->safeMetadata['validation_fields'] ?? null)
                        ? array_values(array_filter(
                            $failure->safeMetadata['validation_fields'],
                            static fn (mixed $value): bool => is_string($value) && $value !== '',
                        ))
                        : [],
                ],
            ),
            new MappingRule(
                id: 'view.not_found',
                exceptionType: \Quantum\View\Exceptions\ViewNotFoundException::class,
                catalogCode: 'resource.not_found',
                priority: 200,
            ),
            new MappingRule(
                id: 'database.execution.retryable',
                exceptionType: \Quantum\Database\Execution\ExecutionException::class,
                catalogCode: 'dependency.unavailable',
                priority: 190,
                predicate: static fn (FailureSnapshot $failure, ExceptionContext $context): bool => ($failure->safeMetadata['db_retryable'] ?? false) === true,
                parameterFactory: static fn (FailureSnapshot $failure, ExceptionContext $context): array => [
                    'phase' => is_string($failure->safeMetadata['db_phase'] ?? null)
                        ? $failure->safeMetadata['db_phase']
                        : null,
                ],
            ),
            new MappingRule(
                id: 'database.execution.default',
                exceptionType: \Quantum\Database\Execution\ExecutionException::class,
                catalogCode: 'operation.indeterminate',
                priority: 180,
            ),
            new MappingRule(
                id: 'database.transaction',
                exceptionType: \Quantum\Database\Transaction\TransactionException::class,
                catalogCode: 'operation.indeterminate',
                priority: 170,
            ),
            new MappingRule(
                id: 'view.render',
                exceptionType: \Quantum\View\Exceptions\ViewRenderException::class,
                catalogCode: 'internal.error',
                priority: 100,
            ),
            new MappingRule(
                id: 'invalid.argument.configuration',
                exceptionType: \InvalidArgumentException::class,
                catalogCode: 'configuration.invalid',
                priority: 10,
                predicate: static fn (FailureSnapshot $failure, ExceptionContext $context): bool =>
                    (($context->attributes['origin'] ?? null) === 'configuration') || str_contains(strtolower($failure->className), 'configuration'),
            ),
            new MappingRule(
                id: 'operation.cancelled',
                exceptionType: \RuntimeException::class,
                catalogCode: 'operation.cancelled',
                priority: 30,
                predicate: static fn (FailureSnapshot $failure, ExceptionContext $context): bool =>
                    ($context->attributes['origin'] ?? null) === 'cancelled',
            ),
        ]);
    }

    public function map(FailureSnapshot $failure, ExceptionContext $context): ?SemanticError
    {
        return $this->inspect($failure, $context)['semantic'];
    }

    /**
     * @return array{
     *   semantic: SemanticError,
     *   matched_rule: ?array{
     *     id: string,
     *     exception_type: string,
     *     catalog_code: string,
     *     priority: int,
     *     specificity_rank: int,
     *     distance: int,
     *     service_id: ?string,
     *     origin_package: ?string
     *   },
     *   used_fallback: bool
     * }
     */
    public function inspect(FailureSnapshot $failure, ExceptionContext $context): array
    {
        foreach ($this->candidateMatchesFor($failure) as $match) {
            $entry = $this->catalog->get($match->rule->catalogCode);

            if ($entry === null) {
                return [
                    'semantic' => $this->fallback($failure),
                    'matched_rule' => null,
                    'used_fallback' => true,
                ];
            }

            try {
                if (! $match->rule->predicateMatches($failure, $context)) {
                    continue;
                }

                return [
                    'semantic' => new SemanticError(
                        code: $entry->code,
                        category: $entry->category,
                        messageKey: $entry->messageKey,
                        safeParameters: array_filter(
                            $match->rule->buildParameters($failure, $context, $entry),
                            static fn (mixed $value): bool => $value !== null,
                        ),
                        severity: $entry->severity,
                        effect: $entry->effect,
                        retryAdvice: $entry->retryAdvice,
                    ),
                    'matched_rule' => [
                        'id' => $match->rule->id,
                        'exception_type' => $match->rule->exceptionType,
                        'catalog_code' => $match->rule->catalogCode,
                        'priority' => $match->rule->priority,
                        'specificity_rank' => $match->specificityRank,
                        'distance' => $match->distance,
                        'service_id' => $match->rule->serviceId,
                        'origin_package' => $match->rule->originPackage,
                    ],
                    'used_fallback' => false,
                ];
            } catch (Throwable) {
                return [
                    'semantic' => $this->fallback($failure),
                    'matched_rule' => null,
                    'used_fallback' => true,
                ];
            }
        }

        return [
            'semantic' => $this->fallback($failure),
            'matched_rule' => null,
            'used_fallback' => true,
        ];
    }

    /**
     * @return list<MappingRuleMatch>
     */
    private function candidateMatchesFor(FailureSnapshot $failure): array
    {
        $candidateMatches = [];

        foreach ($this->rules as $rule) {
            $match = $rule->appliesTo($failure->className);

            if ($match !== null) {
                $candidateMatches[] = $match;
            }
        }

        usort($candidateMatches, static function (MappingRuleMatch $left, MappingRuleMatch $right): int {
            if ($left->rule->priority !== $right->rule->priority) {
                return $right->rule->priority <=> $left->rule->priority;
            }

            if ($left->specificityRank !== $right->specificityRank) {
                return $left->specificityRank <=> $right->specificityRank;
            }

            if ($left->distance !== $right->distance) {
                return $left->distance <=> $right->distance;
            }

            return strcmp($left->rule->id, $right->rule->id);
        });

        return $candidateMatches;
    }

    private function fallback(FailureSnapshot $failure): SemanticError
    {
        $entry = $this->catalog->require('internal.error');

        return new SemanticError(
            code: $entry->code,
            category: $entry->category,
            messageKey: $entry->messageKey,
            severity: $entry->severity,
            effect: $this->fallbackEffect($failure),
            retryAdvice: RetryAdvice::Never,
        );
    }

    private function fallbackEffect(FailureSnapshot $failure): Effect
    {
        return match (strtolower((string) ($failure->safeMetadata['effect'] ?? 'unknown'))) {
            'none' => Effect::None,
            'committed' => Effect::Committed,
            'partial' => Effect::Partial,
            default => Effect::Unknown,
        };
    }
}
