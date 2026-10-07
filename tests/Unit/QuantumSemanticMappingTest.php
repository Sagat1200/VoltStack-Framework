<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Catalog\SemanticCatalogEntry;
use Quantum\Exceptions\Catalog\SemanticErrorCatalog;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;
use Quantum\Exceptions\Mapping\DeterministicSemanticExceptionMapper;
use Quantum\Exceptions\Mapping\MappingRule;
use Quantum\Exceptions\Model\FailureSnapshot;

final class QuantumSemanticMappingTest extends TestCase
{
    public function test_standard_mapper_maps_known_framework_failures(): void
    {
        $mapper = DeterministicSemanticExceptionMapper::standard();
        $context = new ExceptionContext(scopeId: 'scope-1');

        $validation = $mapper->map(new FailureSnapshot(
            className: \Quantum\Validation\Exceptions\ValidationException::class,
            internalMessage: 'The given data was invalid.',
            safeMetadata: ['validation_fields' => ['email', 'password']],
        ), $context);

        $throttled = $mapper->map(new FailureSnapshot(
            className: \Quantum\Auth\Exceptions\ThrottleDeniedException::class,
            internalMessage: 'Authentication throttled.',
            safeMetadata: [
                'auth_reason_code' => 'auth.throttle_denied',
                'retry_after_seconds' => 30,
            ],
        ), $context);

        $database = $mapper->map(new FailureSnapshot(
            className: \Quantum\Database\Execution\ExecutionException::class,
            internalMessage: 'Database execution failed.',
            safeMetadata: [
                'db_retryable' => true,
                'db_phase' => 'statement_execution',
            ],
        ), $context);

        self::assertSame('validation.failed', $validation?->code);
        self::assertSame(['fields' => ['email', 'password']], $validation?->safeParameters);

        self::assertSame('request.throttled', $throttled?->code);
        self::assertSame(30, $throttled?->safeParameters['retry_after_seconds']);
        self::assertSame(RetryAdvice::Conditional, $throttled?->retryAdvice);

        self::assertSame('dependency.unavailable', $database?->code);
        self::assertSame(['phase' => 'statement_execution'], $database?->safeParameters);
    }

    public function test_exact_type_beats_ancestor_and_tie_break_is_deterministic(): void
    {
        $catalog = new SemanticErrorCatalog([
            new SemanticCatalogEntry('custom.parent', SemanticCategory::Internal, 'parent.key'),
            new SemanticCatalogEntry('custom.child', SemanticCategory::Internal, 'child.key'),
            new SemanticCatalogEntry('custom.alpha', SemanticCategory::Internal, 'alpha.key'),
            new SemanticCatalogEntry('internal.error', SemanticCategory::Internal, 'internal.key'),
        ]);

        $mapper = new DeterministicSemanticExceptionMapper($catalog, [
            new MappingRule(
                id: 'z-parent',
                exceptionType: DummyParentException::class,
                catalogCode: 'custom.parent',
                priority: 100,
            ),
            new MappingRule(
                id: 'a-child',
                exceptionType: DummyChildException::class,
                catalogCode: 'custom.child',
                priority: 100,
            ),
            new MappingRule(
                id: 'a-exact-null',
                exceptionType: DummySpecificException::class,
                catalogCode: 'custom.alpha',
                priority: 120,
                predicate: static fn (FailureSnapshot $failure, ExceptionContext $context): bool => false,
            ),
            new MappingRule(
                id: 'b-exact-win',
                exceptionType: DummySpecificException::class,
                catalogCode: 'custom.child',
                priority: 120,
            ),
        ]);

        $context = new ExceptionContext(scopeId: 'scope-2');

        $child = $mapper->map(new FailureSnapshot(DummyChildException::class, 'boom'), $context);
        $specific = $mapper->map(new FailureSnapshot(DummySpecificException::class, 'boom'), $context);

        self::assertSame('custom.child', $child?->code);
        self::assertSame('custom.child', $specific?->code);
    }

    public function test_unknown_failure_falls_back_to_internal_error_and_preserves_effect(): void
    {
        $mapper = DeterministicSemanticExceptionMapper::standard();
        $context = new ExceptionContext(scopeId: 'scope-3');

        $mapped = $mapper->map(new FailureSnapshot(
            className: \RuntimeException::class,
            internalMessage: 'unknown',
            safeMetadata: ['effect' => 'partial'],
        ), $context);

        self::assertSame('internal.error', $mapped?->code);
        self::assertSame(Effect::Partial, $mapped?->effect);
        self::assertSame(RetryAdvice::Never, $mapped?->retryAdvice);
    }

    public function test_catalog_defaults_expose_required_baseline_codes(): void
    {
        $catalog = SemanticErrorCatalog::defaults();

        self::assertNotNull($catalog->get('validation.failed'));
        self::assertNotNull($catalog->get('dependency.unavailable'));
        self::assertSame(500, $catalog->require('internal.error')->httpDefault);
        self::assertSame(SemanticSeverity::Critical, $catalog->require('configuration.invalid')->severity);
    }
}

class DummyParentException extends \RuntimeException {}

class DummyChildException extends DummyParentException {}

class DummySpecificException extends DummyParentException {}
