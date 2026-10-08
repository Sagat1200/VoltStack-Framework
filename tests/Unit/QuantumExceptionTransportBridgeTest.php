<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Bridges\Console\CliTransportMapper;
use Quantum\Exceptions\Bridges\Http\HttpTransportMapper;
use Quantum\Exceptions\Bridges\TransportExceptionRenderer;
use Quantum\Exceptions\Catalog\SemanticErrorCatalog;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Core\ExceptionDescriptorFactory;
use Quantum\Exceptions\Core\ExceptionManager;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\HandlingResultKind;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;
use Quantum\Exceptions\Mapping\DeterministicSemanticExceptionMapper;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\SemanticError;
use Quantum\Exceptions\Normalization\ThrowableNormalizer;
use Quantum\Exceptions\Recovery\DeterministicRecoveryPolicy;
use Quantum\Validation\Exceptions\ValidationException;

final class QuantumExceptionTransportBridgeTest extends TestCase
{
    public function test_http_transport_mapper_prefers_best_json_accept_match(): void
    {
        $mapper = new HttpTransportMapper();
        $descriptor = $this->descriptor('validation.failed');

        $plan = $mapper->map($descriptor, new TransportContext(
            kind: 'http',
            accept: ['text/html;q=0.2, application/problem+json;q=0.9'],
        ));

        self::assertSame('http.problem_json', $plan->target);
        self::assertSame(422, $plan->status);
        self::assertSame('no-store', $plan->headers['Cache-Control'] ?? null);
    }

    public function test_http_transport_mapper_respects_q_zero_for_html(): void
    {
        $mapper = new HttpTransportMapper();
        $descriptor = $this->descriptor('validation.failed');

        $plan = $mapper->map($descriptor, new TransportContext(
            kind: 'http',
            accept: ['text/html;q=0, application/problem+json;q=0.5'],
        ));

        self::assertSame('http.problem_json', $plan->target);
    }

    public function test_renderer_escapes_html_and_strips_cli_control_bytes(): void
    {
        $renderer = new TransportExceptionRenderer();
        $error = new PublicError(
            code: 'internal.error',
            message: '<script>alert(1)</script>' . "\x07",
            occurrenceId: 'occ<script>',
        );

        $html = $renderer->render($error, new \Quantum\Exceptions\Model\TransportPlan(
            target: 'http.html',
            status: 500,
            headers: ['Content-Language' => 'es'],
        ));
        $cli = $renderer->render($error, new \Quantum\Exceptions\Model\TransportPlan(
            target: 'cli.text',
            exitCode: 1,
        ));

        self::assertStringNotContainsString('<script>', $html->bodyBytes);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html->bodyBytes);
        self::assertStringNotContainsString("\x07", $cli->bodyBytes);
    }

    public function test_manager_renders_problem_details_for_api_profile(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new HttpTransportMapper(),
            renderer: new TransportExceptionRenderer(),
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-http-api',
                locale: 'es',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_route_profile' => 'api',
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/problem+json', $result->output?->mediaType);
        self::assertSame(422, $result->output?->status);
        self::assertStringContainsString('"type":"about:blank"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"title":"Unprocessable Content"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"code":"validation.failed"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"errors":[', $result->output?->bodyBytes ?? '');
    }

    public function test_manager_keeps_legacy_json_profile_available(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new HttpTransportMapper(),
            renderer: new TransportExceptionRenderer(),
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-http-legacy-json',
                locale: 'es',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_route_profile' => 'json',
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/json', $result->output?->mediaType);
        self::assertStringContainsString('"occurrence_id"', $result->output?->bodyBytes ?? '');
    }

    public function test_manager_renders_spa_error_protocol_v1_for_registered_profile(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new HttpTransportMapper(),
            renderer: new TransportExceptionRenderer(),
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-http-spa',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_route_profile' => 'spa',
                    'transport_spa_version' => 1,
                    'transport_accept' => ['application/vnd.voltstack.spa-error+json;v=1'],
                    'request_id' => 'req-1',
                    'operation_id' => 'op-1',
                    'navigation_id' => 'nav-1',
                    'spa_target_scope' => 'component',
                    'spa_target_id' => 'cmp_1',
                    'spa_target_revision' => 7,
                    'idempotency_verified' => false,
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/vnd.voltstack.spa-error+json;v=1', $result->output?->mediaType);
        self::assertSame(422, $result->output?->status);
        self::assertStringContainsString('"protocol":"voltstack.spa.error"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"action":"show_fields"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"scope":"component"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"id":"cmp_1"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"revision":7', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"effect":"None"', $result->output?->bodyBytes ?? '');
    }

    public function test_manager_returns_problem_details_when_spa_version_is_incompatible(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new HttpTransportMapper(),
            renderer: new TransportExceptionRenderer(),
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-http-spa-unsupported',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_route_profile' => 'spa',
                    'transport_spa_version' => 2,
                    'transport_accept' => ['application/vnd.voltstack.spa-error+json;v=2'],
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/problem+json', $result->output?->mediaType);
        self::assertSame(406, $result->output?->status);
        self::assertStringContainsString('"code":"spa.protocol_unsupported"', $result->output?->bodyBytes ?? '');
        self::assertStringContainsString('"supported_versions":[1]', $result->output?->bodyBytes ?? '');
    }

    public function test_manager_renders_cli_json_when_profile_requests_it(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new CliTransportMapper(),
            renderer: new TransportExceptionRenderer(),
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-cli-json',
                attributes: [
                    'surface' => 'cli',
                    'transport_kind' => 'cli',
                    'transport_route_profile' => 'json',
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/json', $result->output?->mediaType);
        self::assertSame(2, $result->output?->exitCode);
        self::assertStringContainsString('"exit_code":2', $result->output?->bodyBytes ?? '');
    }

    private function descriptor(string $code): ExceptionDescriptor
    {
        $catalog = SemanticErrorCatalog::defaults();
        $entry = $catalog->require($code);

        return (new ExceptionDescriptorFactory())->create(
            occurrenceId: 'occ-transport',
            failure: new FailureSnapshot(
                className: \RuntimeException::class,
                internalMessage: 'Failure',
                origin: 'exception',
            ),
            semantic: new SemanticError(
                code: $entry->code,
                category: $entry->category,
                messageKey: $entry->messageKey,
                safeParameters: [],
                severity: $entry->severity,
                effect: $entry->effect,
                retryAdvice: $entry->retryAdvice,
            ),
            context: new ExceptionContext(
                scopeId: 'scope-transport',
            ),
        );
    }
}
