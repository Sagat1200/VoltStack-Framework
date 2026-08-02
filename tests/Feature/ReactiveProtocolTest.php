<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Http\RedirectResponse;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use Quantum\Security\CsrfTokenManager;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Component\Component;
use VoltStack\Runtime\Component\ComponentManager;
use VoltStack\Runtime\Protocol\ActionEffectOptions;
use VoltStack\Runtime\Protocol\ActionRuntimePolicyBuilder;
use VoltStack\Runtime\Protocol\HtmlTargetEffectDiffer;

final class ReactiveProtocolTest extends TestCase
{
    public function test_it_executes_a_component_action_through_the_protocol_endpoint(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveCounter::class, [
            'count' => 2,
        ]);
        $snapshot = $components->dehydrate($component);
        $csrf = $app->make(CsrfTokenManager::class)->token();

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $csrf,
                'component' => TestReactiveCounter::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(TestReactiveCounter::class, $payload['component']);
        self::assertSame(3, $payload['snapshot']['state']['count']);
        self::assertSame('increment', $payload['meta']['action']);
        self::assertSame('html.replace', $payload['effects'][0]['type']);
        self::assertSame('root', $payload['effects'][0]['target']);
        self::assertStringContainsString('Count: 3', $payload['effects'][0]['html']);
        self::assertStringContainsString('<button type="button" volt-click="increment">Count: 3</button>', $this->payloadHtml($payload));
        self::assertStringContainsString('data-volt-root="true"', $this->payloadHtml($payload));
    }

    public function test_it_preserves_route_scope_metadata_when_a_component_action_originates_from_a_route(): void
    {
        $app = new Application(sys_get_temp_dir());
        $router = $app->make(Router::class);
        $router->get('/counter/{count}', TestReactiveCounter::class)
            ->name('reactive.counter.show')
            ->componentPage();

        $initialResponse = $app->make(HttpKernel::class)->handle(Request::create('/counter/2'));
        $snapshot = $this->extractSnapshotFromHtml($initialResponse->content());

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveCounter::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot,
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('increment', $payload['meta']['action'] ?? null);
        self::assertSame('reactive.counter.show', $payload['meta']['route']['name'] ?? null);
        self::assertSame(['count' => '2'], $payload['meta']['route']['params'] ?? null);
        self::assertSame('component', $payload['meta']['route']['screen']['kind'] ?? null);
        self::assertSame('navigable', $payload['meta']['route']['screen']['mode'] ?? null);
        self::assertSame('reactive.counter.show', $payload['snapshot']['meta']['route']['name'] ?? null);
        self::assertSame(['count' => '2'], $payload['snapshot']['meta']['route']['params'] ?? null);
        self::assertSame(3, $payload['snapshot']['state']['count'] ?? null);
    }

    public function test_it_returns_a_validation_error_when_the_snapshot_is_invalid(): void
    {
        $app = new Application(sys_get_temp_dir());

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveCounter::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => [
                    'component' => TestReactiveCounter::class,
                    'state' => ['count' => 999],
                    'checksum' => 'invalid',
                    'meta' => [],
                ],
            ],
        ));

        self::assertSame(422, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.invalid_snapshot', $payload['error']['code']);
        self::assertSame(422, $payload['error']['status']);
        self::assertSame('Snapshot checksum is invalid.', $payload['error']['message']);
    }

    public function test_it_rejects_reactive_requests_without_a_valid_csrf_token(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveCounter::class, [
            'count' => 2,
        ]);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                'component' => TestReactiveCounter::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(419, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('security.csrf_token_mismatch', $payload['error']['code']);
        self::assertSame(419, $payload['error']['status']);
        self::assertSame('CSRF token mismatch.', $payload['error']['message']);
    }

    public function test_it_returns_protocol_error_metadata_when_a_runtime_request_hits_a_missing_route(): void
    {
        $app = new Application(sys_get_temp_dir());

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/missing-runtime-endpoint',
            'POST',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{}',
        ));

        self::assertSame(404, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('route.not_found', $payload['error']['code']);
        self::assertSame(404, $payload['error']['status']);
        self::assertSame('Not Found', $payload['error']['message']);
    }

    public function test_it_returns_protocol_error_metadata_for_method_not_allowed_on_the_runtime_endpoint(): void
    {
        $app = new Application(sys_get_temp_dir());

        $response = $app->make(HttpKernel::class)->handle(Request::create('/_volt/action', 'GET'));

        self::assertSame(405, $response->statusCode());
        self::assertSame('POST, OPTIONS', $response->headers()['Allow']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('route.method_not_allowed', $payload['error']['code']);
        self::assertSame(405, $payload['error']['status']);
        self::assertSame(['POST', 'OPTIONS'], $payload['error']['allow']);
        self::assertSame('POST, OPTIONS', $payload['error']['allowHeader']);
        self::assertSame('Method Not Allowed', $payload['error']['message']);
    }

    public function test_it_returns_protocol_error_metadata_when_a_component_action_crashes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveExplosiveComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveExplosiveComponent::class,
                'action' => 'explode',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(500, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('server.error', $payload['error']['code']);
        self::assertSame(500, $payload['error']['status']);
        self::assertSame('Server Error', $payload['error']['message']);
    }

    public function test_it_returns_a_semantic_protocol_error_when_the_component_action_does_not_exist(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveCounter::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveCounter::class,
                'action' => 'missingAction',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(422, $response->statusCode());
        self::assertSame('runtime.action_not_allowed', $response->headers()['X-Volt-Error-Code'] ?? null);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.action_not_allowed', $payload['error']['code']);
        self::assertSame(422, $payload['error']['status']);
        self::assertSame('Component action is not allowed.', $payload['error']['message']);
    }

    public function test_it_returns_a_semantic_protocol_error_when_the_component_action_is_reserved(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveCounter::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveCounter::class,
                'action' => 'render',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(422, $response->statusCode());
        self::assertSame('runtime.action_not_allowed', $response->headers()['X-Volt-Error-Code'] ?? null);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.action_not_allowed', $payload['error']['code']);
        self::assertSame(422, $payload['error']['status']);
        self::assertSame('Component action is not allowed.', $payload['error']['message']);
    }

    public function test_it_returns_a_semantic_protocol_error_when_component_render_fails_after_an_action(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveRenderFailureComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveRenderFailureComponent::class,
                'action' => 'breakRender',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(500, $response->statusCode());
        self::assertSame('runtime.component_render_failed', $response->headers()['X-Volt-Error-Code'] ?? null);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.component_render_failed', $payload['error']['code']);
        self::assertSame(500, $payload['error']['status']);
        self::assertSame('Server Error', $payload['error']['message']);
    }

    public function test_it_applies_model_updates_and_form_params_before_running_the_action(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveFormComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveFormComponent::class,
                'action' => 'save',
                'params' => [
                    'note' => 'saved-from-submit',
                    '_token' => 'ignored-token-field',
                ],
                'updates' => [
                    'title' => 'VoltStack Title',
                ],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('VoltStack Title', $payload['snapshot']['state']['title']);
        self::assertSame('saved-from-submit', $payload['snapshot']['state']['savedNote']);
        self::assertSame('html.replace', $payload['effects'][0]['type']);
        self::assertStringContainsString('value="VoltStack Title"', $this->payloadHtml($payload));
        self::assertStringContainsString('Saved: saved-from-submit', $this->payloadHtml($payload));
    }

    public function test_it_returns_semantic_validation_errors_when_a_reactive_form_submit_fails(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveFormComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveFormComponent::class,
                'action' => 'save',
                'params' => [
                    'note' => 'should-not-save',
                ],
                'updates' => [
                    'title' => 'no',
                ],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(422, $response->statusCode());
        self::assertSame('runtime.validation_failed', $response->headers()['X-Volt-Error-Code'] ?? null);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.validation_failed', $payload['error']['code']);
        self::assertSame(422, $payload['error']['status']);
        self::assertSame('The given data was invalid.', $payload['error']['message']);
        self::assertSame(
            ['The title field must be at least 3.'],
            $payload['error']['errors']['title'] ?? null,
        );
    }

    public function test_it_returns_validation_failed_when_the_reactive_payload_shape_is_invalid(): void
    {
        $app = new Application(sys_get_temp_dir());

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveFormComponent::class,
                'action' => 'save',
                'params' => 'invalid',
                'updates' => 'invalid',
                'snapshot' => 'invalid',
            ],
        ));

        self::assertSame(422, $response->statusCode());
        self::assertSame('runtime.validation_failed', $response->headers()['X-Volt-Error-Code'] ?? null);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('protocol-error', $payload['error']['kind']);
        self::assertSame('runtime.validation_failed', $payload['error']['code']);
        self::assertSame('The given data was invalid.', $payload['error']['message']);
        self::assertSame(
            ['The snapshot field must be an array.'],
            $payload['error']['errors']['snapshot'] ?? null,
        );
        self::assertSame(
            ['The params field must be an array.'],
            $payload['error']['errors']['params'] ?? null,
        );
        self::assertSame(
            ['The updates field must be an array.'],
            $payload['error']['errors']['updates'] ?? null,
        );
    }

    public function test_it_allows_internal_sync_requests_to_apply_updates_without_a_public_action(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveInternalSyncComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveInternalSyncComponent::class,
                'action' => '__volt_sync__',
                'params' => [],
                'updates' => [
                    'title' => 'Synced from runtime',
                ],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('__volt_sync__', $payload['meta']['action']);
        self::assertSame('Synced from runtime', $payload['snapshot']['state']['title']);
        self::assertCount(1, $payload['effects']);
        self::assertSame('text.update', $payload['effects'][0]['type']);
        self::assertSame('sync-title', $payload['effects'][0]['target']);
        self::assertSame('Mirror: Synced from runtime', $payload['effects'][0]['value']);
        self::assertNull($payload['html']);
    }

    public function test_it_combines_selectively_synced_params_and_updates_before_running_an_action(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveSelectiveSyncComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveSelectiveSyncComponent::class,
                'action' => 'persist',
                'params' => [
                    'alias' => 'client-alias-from-state',
                    'category' => 'review',
                ],
                'updates' => [
                    'title' => 'Synced title',
                    'serverAliasMirror' => 'Synced alias mirror',
                ],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('persist', $payload['meta']['action']);
        self::assertSame('Synced title', $payload['snapshot']['state']['title']);
        self::assertSame('Synced alias mirror', $payload['snapshot']['state']['serverAliasMirror']);
        self::assertSame('client-alias-from-state', $payload['snapshot']['state']['savedAlias']);
        self::assertSame('review', $payload['snapshot']['state']['savedCategory']);
        self::assertStringContainsString('Title: Synced title', $this->payloadHtml($payload));
        self::assertStringContainsString('Alias mirror: Synced alias mirror', $this->payloadHtml($payload));
        self::assertStringContainsString('Saved alias: client-alias-from-state', $this->payloadHtml($payload));
        self::assertStringContainsString('Saved category: review', $this->payloadHtml($payload));
    }

    public function test_it_combines_boolean_and_text_updates_with_selectively_synced_params_in_the_same_request(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveSelectiveSyncComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveSelectiveSyncComponent::class,
                'action' => 'persist',
                'params' => [
                    'alias' => 'second-alias',
                    'category' => 'done',
                    'note' => 'queued-from-client',
                ],
                'updates' => [
                    'title' => 'Second synced title',
                    'body' => 'Second synced body',
                    'serverEnabled' => true,
                    'serverAliasMirror' => 'Second alias mirror',
                ],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('persist', $payload['meta']['action']);
        self::assertSame('Second synced title', $payload['snapshot']['state']['title']);
        self::assertSame('Second synced body', $payload['snapshot']['state']['body']);
        self::assertTrue($payload['snapshot']['state']['serverEnabled']);
        self::assertSame('Second alias mirror', $payload['snapshot']['state']['serverAliasMirror']);
        self::assertSame('second-alias', $payload['snapshot']['state']['savedAlias']);
        self::assertSame('done', $payload['snapshot']['state']['savedCategory']);
        self::assertSame('queued-from-client', $payload['snapshot']['state']['savedNote']);
        self::assertStringContainsString('Body: Second synced body', $this->payloadHtml($payload));
        self::assertStringContainsString('Enabled: true', $this->payloadHtml($payload));
        self::assertStringContainsString('Saved note: queued-from-client', $this->payloadHtml($payload));
    }

    public function test_it_returns_a_navigation_effect_when_the_action_redirects(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveRedirectComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveRedirectComponent::class,
                'action' => 'goHome',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('navigate', $payload['effects'][0]['type']);
        self::assertSame('/', $payload['effects'][0]['url']);
        self::assertFalse($payload['effects'][0]['replace']);
    }

    public function test_it_returns_granular_effects_for_targeted_html_changes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveTargetedComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveTargetedComponent::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $payload['effects']);
        self::assertSame('attribute.set', $payload['effects'][0]['type']);
        self::assertSame('action-button', $payload['effects'][0]['target']);
        self::assertSame('disabled', $payload['effects'][0]['name']);
        self::assertSame('disabled', $payload['effects'][0]['value']);
        self::assertSame('text.update', $payload['effects'][1]['type']);
        self::assertSame('counter-value', $payload['effects'][1]['target']);
        self::assertSame('1', $payload['effects'][1]['value']);
        self::assertNull($payload['html']);
    }

    public function test_it_returns_targeted_effects_for_full_document_html_changes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveDocumentTargetedComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveDocumentTargetedComponent::class,
                'action' => 'rename',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('text.update', $payload['effects'][0]['type']);
        self::assertSame('doc-title', $payload['effects'][0]['target']);
        self::assertSame('Renamed', $payload['effects'][0]['value']);
    }

    public function test_it_returns_targeted_effects_for_wrapped_root_with_full_document_html_changes(): void
    {
        $differ = new HtmlTargetEffectDiffer();

        $previous = '<div data-volt-root="true"><!DOCTYPE html><html><head><title>Demo</title></head><body><main><span data-volt-target="doc-title">Initial</span></main></body></html></div>';
        $next = '<div data-volt-root="true"><!DOCTYPE html><html><head><title>Demo</title></head><body><main><span data-volt-target="doc-title">Renamed</span></main></body></html></div>';

        $effects = $differ->diff($previous, $next);

        self::assertIsArray($effects);
        self::assertCount(1, $effects);
        self::assertSame('text.update', $effects[0]['type']);
        self::assertSame('doc-title', $effects[0]['target']);
        self::assertSame('Renamed', $effects[0]['value']);
    }

    public function test_it_returns_class_and_style_effects_for_semantic_target_changes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveStyledTargetedComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveStyledTargetedComponent::class,
                'action' => 'activate',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(4, $payload['effects']);
        self::assertSame('class.toggle', $payload['effects'][0]['type']);
        self::assertSame('status-badge', $payload['effects'][0]['target']);
        self::assertSame('active', $payload['effects'][0]['class']);
        self::assertTrue($payload['effects'][0]['force']);
        self::assertSame('style.set', $payload['effects'][1]['type']);
        self::assertSame('status-badge', $payload['effects'][1]['target']);
        self::assertSame('color', $payload['effects'][1]['property']);
        self::assertSame('red', $payload['effects'][1]['value']);
        self::assertSame('style.set', $payload['effects'][2]['type']);
        self::assertSame('status-badge', $payload['effects'][2]['target']);
        self::assertSame('font-weight', $payload['effects'][2]['property']);
        self::assertSame('700', $payload['effects'][2]['value']);
        self::assertSame('text.update', $payload['effects'][3]['type']);
        self::assertSame('status-text', $payload['effects'][3]['target']);
        self::assertSame('Active', $payload['effects'][3]['value']);
        self::assertNull($payload['html']);
    }

    public function test_it_allows_the_backend_to_attach_transition_options_to_generated_effects(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveTransitionedComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveTransitionedComponent::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('text.update', $payload['effects'][0]['type']);
        self::assertSame('counter-value', $payload['effects'][0]['target']);
        self::assertSame('pop', $payload['effects'][0]['transition']['name']);
        self::assertSame(220, $payload['effects'][0]['transition']['duration']);
        self::assertSame('glow', $payload['effects'][0]['transitions']['update']['name']);
        self::assertSame('volt-transition-soft-edge', $payload['effects'][0]['transitions']['update']['className']);
    }

    public function test_it_allows_transition_shortcuts_on_action_effect_match(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveMatchedTransitionComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveMatchedTransitionComponent::class,
                'action' => 'increment',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('text.update', $payload['effects'][0]['type']);
        self::assertSame('counter-value', $payload['effects'][0]['target']);
        self::assertSame('fade', $payload['effects'][0]['transition']['name']);
        self::assertSame(180, $payload['effects'][0]['transition']['duration']);
    }

    public function test_it_allows_manual_effects_to_be_appended_after_generated_effects(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveManualEffectsComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveManualEffectsComponent::class,
                'action' => 'save',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(4, $payload['effects']);
        self::assertSame('text.update', $payload['effects'][0]['type']);
        self::assertSame('saved-status', $payload['effects'][0]['target']);
        self::assertSame('Saved', $payload['effects'][0]['value']);
        self::assertSame('focus', $payload['effects'][1]['type']);
        self::assertSame('title-input', $payload['effects'][1]['target']);
        self::assertSame('attribute.set', $payload['effects'][2]['type']);
        self::assertSame('title-input', $payload['effects'][2]['target']);
        self::assertSame('data-saved', $payload['effects'][2]['name']);
        self::assertSame('true', $payload['effects'][2]['value']);
        self::assertSame('dispatch.event', $payload['effects'][3]['type']);
        self::assertSame('title-input', $payload['effects'][3]['target']);
        self::assertSame('demo.saved', $payload['effects'][3]['event']);
        self::assertSame(['count' => 1], $payload['effects'][3]['detail']);
        self::assertStringContainsString('Saved', $this->payloadHtml($payload));
    }

    public function test_it_allows_runtime_state_policies_to_be_emitted_from_a_policies_callback(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveRuntimePolicyComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveRuntimePolicyComponent::class,
                'action' => 'save',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $payload['effects']);
        self::assertSame('html.replace', $payload['effects'][0]['type']);

        self::assertSame('runtime.policy', $payload['effects'][1]['type']);
        self::assertSame('success', $payload['effects'][1]['state']);
        self::assertSame('save', $payload['effects'][1]['scopeAction']);
        self::assertSame('save-form', $payload['effects'][1]['scopeTarget']);
        self::assertSame('200ms', $payload['effects'][1]['timeout']);
        self::assertSame('1.2s', $payload['effects'][1]['minDuration']);

        self::assertSame('runtime.policy', $payload['effects'][2]['type']);
        self::assertSame('dirty', $payload['effects'][2]['state']);
        self::assertSame('title', $payload['effects'][2]['scopeTarget']);
        self::assertSame('200ms', $payload['effects'][2]['debounce']);
    }

    public function test_it_exposes_additional_semantic_runtime_policy_action_shortcuts(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveSemanticRuntimePolicyAliasesComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveSemanticRuntimePolicyAliasesComponent::class,
                'action' => 'submit',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(4, $payload['effects']);

        self::assertSame('runtime.policy', $payload['effects'][0]['type']);
        self::assertSame('submit', $payload['effects'][0]['scopeAction']);
        self::assertSame('submit-form', $payload['effects'][0]['scopeTarget']);
        self::assertSame('success', $payload['effects'][0]['state']);

        self::assertSame('runtime.policy', $payload['effects'][1]['type']);
        self::assertSame('increment', $payload['effects'][1]['scopeAction']);
        self::assertSame('counter-panel', $payload['effects'][1]['scopeTarget']);
        self::assertSame('loading', $payload['effects'][1]['state']);

        self::assertSame('runtime.policy', $payload['effects'][2]['type']);
        self::assertSame('update', $payload['effects'][2]['scopeAction']);
        self::assertSame('record-row', $payload['effects'][2]['scopeTarget']);
        self::assertSame('dirty', $payload['effects'][2]['state']);

        self::assertSame('runtime.policy', $payload['effects'][3]['type']);
        self::assertSame('delete', $payload['effects'][3]['scopeAction']);
        self::assertSame('danger-zone', $payload['effects'][3]['scopeTarget']);
        self::assertSame('error', $payload['effects'][3]['state']);
    }

    public function test_it_restores_the_previous_scope_after_a_group_block(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveGroupedEffectsComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveGroupedEffectsComponent::class,
                'action' => 'save',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $payload['effects']);
        self::assertSame('focus', $payload['effects'][0]['type']);
        self::assertSame('title-input', $payload['effects'][0]['target']);
        self::assertSame('dispatch.event', $payload['effects'][1]['type']);
        self::assertSame('title-input', $payload['effects'][1]['target']);
        self::assertSame('dispatch.event', $payload['effects'][2]['type']);
        self::assertArrayNotHasKey('target', $payload['effects'][2]);
        self::assertSame('demo.outside-group', $payload['effects'][2]['event']);
    }

    public function test_it_returns_dom_append_for_stable_target_lists(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveListComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveListComponent::class,
                'action' => 'append',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('dom.append', $payload['effects'][0]['type']);
        self::assertSame('todo-list', $payload['effects'][0]['target']);
        self::assertSame('beforeend', $payload['effects'][0]['position']);
        self::assertStringContainsString('data-volt-key="item-3"', $payload['effects'][0]['html']);
        self::assertStringContainsString('Third', $payload['effects'][0]['html']);
    }

    public function test_it_returns_dom_remove_for_stable_target_lists(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveListComponent::class, [
            'items' => [
                ['key' => 'item-1', 'label' => 'First'],
                ['key' => 'item-2', 'label' => 'Second'],
                ['key' => 'item-3', 'label' => 'Third'],
            ],
        ]);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveListComponent::class,
                'action' => 'removeLast',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('dom.remove', $payload['effects'][0]['type']);
        self::assertSame('[data-volt-target="todo-list"] > [data-volt-key="item-3"]', $payload['effects'][0]['selector']);
    }

    public function test_it_returns_html_replace_for_a_single_changed_item_in_a_keyed_list(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveListComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveListComponent::class,
                'action' => 'updateSecond',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('html.replace', $payload['effects'][0]['type']);
        self::assertSame('[data-volt-target="todo-list"] > [data-volt-key="item-2"]', $payload['effects'][0]['selector']);
        self::assertStringContainsString('data-volt-key="item-2"', $payload['effects'][0]['html']);
        self::assertStringContainsString('Second updated', $payload['effects'][0]['html']);
        self::assertTrue($payload['effects'][0]['outer']);
    }

    public function test_it_returns_dom_insert_for_a_middle_insertion_in_a_keyed_list(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveListComponent::class);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveListComponent::class,
                'action' => 'insertMiddle',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('dom.insert', $payload['effects'][0]['type']);
        self::assertSame('todo-list', $payload['effects'][0]['target']);
        self::assertSame('[data-volt-target="todo-list"] > [data-volt-key="item-2"]', $payload['effects'][0]['beforeSelector']);
        self::assertSame('beforebegin', $payload['effects'][0]['position']);
        self::assertStringContainsString('data-volt-key="item-1-5"', $payload['effects'][0]['html']);
        self::assertStringContainsString('Between', $payload['effects'][0]['html']);
    }

    public function test_it_returns_dom_move_for_a_reordered_keyed_list(): void
    {
        $app = new Application(sys_get_temp_dir());
        $components = $app->make(ComponentManager::class);
        $component = $components->mount(TestReactiveListComponent::class, [
            'items' => [
                ['key' => 'item-1', 'label' => 'First'],
                ['key' => 'item-2', 'label' => 'Second'],
                ['key' => 'item-3', 'label' => 'Third'],
            ],
        ]);
        $snapshot = $components->dehydrate($component);

        $response = $app->make(HttpKernel::class)->handle(Request::create(
            '/_volt/action',
            'POST',
            [],
            [
                '_token' => $app->make(CsrfTokenManager::class)->token(),
                'component' => TestReactiveListComponent::class,
                'action' => 'moveLastToFirst',
                'params' => [],
                'snapshot' => $snapshot->toArray(),
            ],
        ));

        self::assertSame(200, $response->statusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload['effects']);
        self::assertSame('dom.move', $payload['effects'][0]['type']);
        self::assertSame('[data-volt-target="todo-list"] > [data-volt-key="item-3"]', $payload['effects'][0]['selector']);
        self::assertSame('todo-list', $payload['effects'][0]['parentTarget']);
        self::assertSame('[data-volt-target="todo-list"] > [data-volt-key="item-1"]', $payload['effects'][0]['beforeSelector']);
        self::assertSame('beforebegin', $payload['effects'][0]['position']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSnapshotFromHtml(string $html): array
    {
        preg_match('/data-volt-snapshot="([^"]+)"/', $html, $matches);

        self::assertIsArray($matches);
        self::assertArrayHasKey(1, $matches);

        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

        return $snapshot;
    }

    private function payloadHtml(array $payload): string
    {
        $effects = $payload['effects'] ?? null;

        if (is_array($effects)) {
            foreach ($effects as $effect) {
                if (! is_array($effect)) {
                    continue;
                }

                if (($effect['type'] ?? null) !== 'html.replace') {
                    continue;
                }

                if (is_string($effect['html'] ?? null)) {
                    return $effect['html'];
                }
            }
        }

        return is_string($payload['html'] ?? null) ? $payload['html'] : '';
    }
}

final class TestReactiveCounter extends Component
{
    public int $count = 0;

    public function mount(int $count = 0): void
    {
        $this->count = $count;
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return sprintf(
            '<button type="button" volt-click="increment">Count: %d</button>',
            $this->count,
        );
    }
}

final class TestReactiveExplosiveComponent extends Component
{
    public function explode(): void
    {
        throw new \RuntimeException('Boom from reactive action.');
    }

    public function render(): string
    {
        return '<button type="button" volt-click="explode">Explode</button>';
    }
}

final class TestReactiveRenderFailureComponent extends Component
{
    public bool $shouldFailRender = false;

    public function breakRender(): void
    {
        $this->shouldFailRender = true;
    }

    public function render(): string
    {
        if ($this->shouldFailRender) {
            throw new \RuntimeException('Reactive render exploded.');
        }

        return '<button type="button" volt-click="breakRender">Break render</button>';
    }
}

final class TestReactiveFormComponent extends Component
{
    public string $title = '';

    public string $savedNote = '';

    public function save(string $note = ''): void
    {
        $this->validate([
            'title' => $this->title,
        ], [
            'title' => ['required', 'string', 'min:3'],
        ]);

        $this->savedNote = $note;
    }

    public function render(): string
    {
        return sprintf(
            '<form volt-submit="save">%s<input type="text" volt-model="title" value="%s"><button type="submit">Save</button><span>Saved: %s</span></form>',
            csrf_field(),
            e($this->title),
            e($this->savedNote),
        );
    }
}

final class TestReactiveRedirectComponent extends Component
{
    public function goHome(): RedirectResponse
    {
        return response()->redirect('/');
    }

    public function render(): string
    {
        return '<button type="button" volt-click="goHome">Go home</button>';
    }
}

final class TestReactiveInternalSyncComponent extends Component
{
    public string $title = 'Initial title';

    public function render(): string
    {
        return sprintf(
            '<div data-volt-target="sync-title">Mirror: %s</div>',
            e($this->title),
        );
    }
}

final class TestReactiveTargetedComponent extends Component
{
    public int $count = 0;

    public bool $locked = false;

    public function increment(): void
    {
        $this->count++;
        $this->locked = true;
    }

    public function render(): string
    {
        return sprintf(
            '<div><span data-volt-target="counter-value">%d</span><button data-volt-target="action-button" type="button"%s>Increment</button></div>',
            $this->count,
            $this->locked ? ' disabled' : '',
        );
    }
}

final class TestReactiveSelectiveSyncComponent extends Component
{
    public string $title = 'Initial selective title';

    public string $body = 'Initial selective body';

    public bool $serverEnabled = false;

    public string $serverAliasMirror = 'Initial alias mirror';

    public string $savedAlias = '';

    public string $savedCategory = '';

    public string $savedNote = '';

    public function persist(string $alias = '', string $category = '', string $note = ''): void
    {
        $this->savedAlias = $alias;
        $this->savedCategory = $category;
        $this->savedNote = $note;
    }

    public function render(): string
    {
        return sprintf(
            '<div><span>Title: %s</span><span>Body: %s</span><span>Enabled: %s</span><span>Alias mirror: %s</span><span>Saved alias: %s</span><span>Saved category: %s</span><span>Saved note: %s</span></div>',
            e($this->title),
            e($this->body),
            $this->serverEnabled ? 'true' : 'false',
            e($this->serverAliasMirror),
            e($this->savedAlias),
            e($this->savedCategory),
            e($this->savedNote),
        );
    }
}

final class TestReactiveDocumentTargetedComponent extends Component
{
    public string $title = 'Initial';

    public function rename(): void
    {
        $this->title = 'Renamed';
    }

    public function render(): string
    {
        return sprintf(
            '<!DOCTYPE html><html><head><title>Demo</title></head><body><main><span data-volt-target="doc-title">%s</span></main></body></html>',
            e($this->title),
        );
    }
}

final class TestReactiveStyledTargetedComponent extends Component
{
    public bool $active = false;

    public function activate(): void
    {
        $this->active = true;
    }

    public function render(): string
    {
        return sprintf(
            '<div><span data-volt-target="status-badge" class="badge%s"%s>Badge</span><span data-volt-target="status-text">%s</span></div>',
            $this->active ? ' active' : '',
            $this->active ? ' style="color:red;font-weight:700"' : '',
            $this->active ? 'Active' : 'Idle',
        );
    }
}

final class TestReactiveTransitionedComponent extends Component
{
    public int $count = 0;

    public function increment(): ActionEffectOptions
    {
        $this->count++;

        return ActionEffectOptions::make()
            ->transitions()
            ->onTarget('counter-value')
            ->forTextUpdate()
            ->pop(220)
            ->onTarget('counter-value')
            ->forTextUpdate()
            ->updateAs('glow', className: 'volt-transition-soft-edge')
            ->end();
    }

    public function render(): string
    {
        return sprintf(
            '<div><span data-volt-target="counter-value">%d</span></div>',
            $this->count,
        );
    }
}

final class TestReactiveManualEffectsComponent extends Component
{
    public int $count = 0;

    public function save(): ActionEffectOptions
    {
        $this->count++;

        return ActionEffectOptions::make()
            ->transitions()
            ->onTarget('saved-status')
            ->forTextUpdate()
            ->glow()
            ->end()
            ->effects()
            ->onTarget('title-input')
            ->focusAndSetAttribute('data-saved', 'true')
            ->event('demo.saved', ['count' => $this->count])
            ->end();
    }

    public function render(): string
    {
        return sprintf(
            '<div><input data-volt-target="title-input" value="demo"><span data-volt-target="saved-status">%s</span></div>',
            $this->count > 0 ? 'Saved' : 'Idle',
        );
    }
}

final class TestReactiveMatchedTransitionComponent extends Component
{
    public int $count = 0;

    public function increment(): ActionEffectOptions
    {
        $this->count++;

        return ActionEffectOptions::make()
            ->onTarget('counter-value')
            ->when('text.update')
            ->fade(180);
    }

    public function render(): string
    {
        return sprintf(
            '<div><span data-volt-target="counter-value">%d</span></div>',
            $this->count,
        );
    }
}

final class TestReactiveRuntimePolicyComponent extends Component
{
    public bool $saved = false;

    public function save(): ActionEffectOptions
    {
        $this->saved = true;

        return ActionEffectOptions::make()
            ->policies(fn(ActionRuntimePolicyBuilder $policies) => $policies
                ->onTarget('save-form')
                ->forSave()
                ->success('200ms', '1.2s')
                ->onTarget('title')
                ->dirty('200ms'));
    }

    public function render(): string
    {
        return sprintf(
            '<div data-volt-target="save-form"><span data-volt-target="saved-status">%s</span></div>',
            $this->saved ? 'Saved' : 'Idle',
        );
    }
}

final class TestReactiveSemanticRuntimePolicyAliasesComponent extends Component
{
    public function submit(): ActionEffectOptions
    {
        return ActionEffectOptions::make()
            ->policies(fn(ActionRuntimePolicyBuilder $policies) => $policies
                ->onTarget('submit-form')
                ->forSubmit()
                ->success('300ms')
                ->onTarget('counter-panel')
                ->forIncrement()
                ->loading('150ms', '700ms')
                ->onTarget('record-row')
                ->forUpdate()
                ->dirty('200ms')
                ->onTarget('danger-zone')
                ->forDelete()
                ->error('3s'));
    }

    public function render(): string
    {
        return '<div><form data-volt-target="submit-form"></form></div>';
    }
}

final class TestReactiveGroupedEffectsComponent extends Component
{
    public function save(): ActionEffectOptions
    {
        return ActionEffectOptions::make()
            ->effects()
            ->onTarget('title-input')
            ->focusAndEvent('demo.inside-group')
            ->end()
            ->event('demo.outside-group');
    }

    public function render(): string
    {
        return '<div><input data-volt-target="title-input" value="demo"></div>';
    }
}

final class TestReactiveListComponent extends Component
{
    /**
     * @var array<int, array{key: string, label: string}>
     */
    public array $items = [
        ['key' => 'item-1', 'label' => 'First'],
        ['key' => 'item-2', 'label' => 'Second'],
    ];

    /**
     * @param array<int, array{key: string, label: string}> $items
     */
    public function mount(array $items = []): void
    {
        if ($items !== []) {
            $this->items = $items;
        }
    }

    public function append(): void
    {
        $this->items[] = [
            'key' => 'item-' . (count($this->items) + 1),
            'label' => 'Third',
        ];
    }

    public function removeLast(): void
    {
        array_pop($this->items);
    }

    public function updateSecond(): void
    {
        $this->items[1]['label'] = 'Second updated';
    }

    public function insertMiddle(): void
    {
        array_splice($this->items, 1, 0, [[
            'key' => 'item-1-5',
            'label' => 'Between',
        ]]);
    }

    public function moveLastToFirst(): void
    {
        $last = array_pop($this->items);

        if ($last === null) {
            return;
        }

        array_unshift($this->items, $last);
    }

    public function render(): string
    {
        $items = array_map(
            static fn(array $item): string => sprintf(
                '<li data-volt-key="%s">%s</li>',
                e($item['key']),
                e($item['label']),
            ),
            $this->items,
        );

        return '<ul data-volt-target="todo-list">' . implode('', $items) . '</ul>';
    }
}
