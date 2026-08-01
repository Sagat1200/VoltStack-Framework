<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SkeletonSpaRoadmapTest extends TestCase
{
    private static string $skeletonBasePath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$skeletonBasePath = self::locateSkeletonBasePath();

        require_once self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    public function test_routing_lab_index_exposes_public_navigation_targets(): void
    {
        $response = $this->handleSkeletonRequest('/routing-lab');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Routing Lab', $response->content());
        self::assertStringContainsString('href="/routing-lab/users/15"', $response->content());
        self::assertStringContainsString('href="/routing-lab/reports/export"', $response->content());
        self::assertStringContainsString('href="/routing-lab/private"', $response->content());
        self::assertStringContainsString('/_volt/routes-manifest.json', $response->content());
        self::assertStringContainsString('volt:navigate', $response->content());
    }

    public function test_home_screen_is_spa_capable_from_first_render(): void
    {
        $response = $this->handleSkeletonRequest('/');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('VoltStack Framework', $response->content());
        self::assertStringContainsString('href="/spaReactive"', $response->content());
        self::assertStringContainsString('volt:navigate', $response->content());
        self::assertStringContainsString('data-volt-document="spa"', $response->content());
        self::assertStringContainsString('data-volt-navigation-mode="auto"', $response->content());
        self::assertStringContainsString('data-volt-layout="app"', $response->content());
        self::assertStringContainsString('data-volt-head-key="runtime-busy-session-bridge"', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-runtime="true"'));
        self::assertMatchesRegularExpression('/<script data-volt-runtime="true" src="\/_volt\/runtime\.js\?v=\d+" defer><\/script>/', $response->content());
    }

    public function test_home_first_click_target_emits_spa_navigation_payload(): void
    {
        $home = $this->handleSkeletonRequest('/');
        $navigation = $this->handleSkeletonNavigationRequest('/spaReactive');
        $payload = $this->decodeNavigationPayload($navigation);

        self::assertSame(200, $home->statusCode(), $home->content());
        self::assertStringContainsString('href="/spaReactive"', $home->content());
        self::assertStringContainsString('volt:navigate', $home->content());

        self::assertSame(200, $navigation->statusCode(), $navigation->content());
        self::assertSame('/spaReactive', $payload['navigation']['target'] ?? null);
        self::assertSame('spaReactive', $payload['screen']['route'] ?? null);
        self::assertArrayHasKey('policy', $payload);
        self::assertNull($payload['redirect'] ?? null);
        self::assertNull($payload['error'] ?? null);
    }

    public function test_spa_reactive_entry_screen_exposes_component_navigation_targets(): void
    {
        $response = $this->handleSkeletonRequest('/spaReactive');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Sistema de Analisis de Runtime SPA Full Reactive', $response->content());
        self::assertStringContainsString('href="/counterExample"', $response->content());
        self::assertStringContainsString('href="/formExample"', $response->content());
        self::assertStringContainsString('href="/cacheExample"', $response->content());
        self::assertStringContainsString('href="/fragmentCache"', $response->content());
        self::assertStringContainsString('href="/runtimeFocus"', $response->content());
        self::assertStringContainsString('href="/runtimeEffectsD2"', $response->content());
        self::assertStringContainsString('href="/runtimeState"', $response->content());
        self::assertStringContainsString('href="/runtimePersist"', $response->content());
        self::assertStringContainsString('href="/runtimeRequestLab"', $response->content());
        self::assertStringContainsString('data-volt-document="spa"', $response->content());
        self::assertStringContainsString('data-volt-navigation-mode="auto"', $response->content());
        self::assertStringContainsString('data-volt-layout="spa"', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-runtime="true"'));
    }

    public function test_key_spa_lab_demo_routes_remain_live_for_the_visual_index(): void
    {
        $routes = [
            '/cacheExample',
            '/fragmentCache',
            '/navigationPolicy',
            '/navigationTransition',
            '/runtimeAdvancedDirectives',
            '/runtimeFocus',
            '/runtimeEffectsD2',
            '/runtimeRequestLab',
            '/runtimePersist',
            '/runtimeState',
        ];

        foreach ($routes as $route) {
            $response = $this->handleSkeletonRequest($route);

            self::assertSame(200, $response->statusCode(), sprintf('Route %s did not return 200.', $route));
        }
    }

    public function test_runtime_effects_d2_screen_exposes_contract_markers_for_manual_qa(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeEffectsD2');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Runtime Effects D2', $response->content());
        self::assertStringContainsString('data-runtime-effects-d2="true"', $response->content());
        self::assertStringContainsString('volt-click="applyAttributeSet"', $response->content());
        self::assertStringContainsString('volt-click="applyAttributeRemove"', $response->content());
        self::assertStringContainsString('volt-click="applyClientStateSet"', $response->content());
        self::assertStringContainsString('volt-click="applySharedStateSet"', $response->content());
        self::assertStringContainsString('data-volt-target="effects-d2-attr-box"', $response->content());
        self::assertStringContainsString('data-volt-target="effects-d2-blur-input"', $response->content());
        self::assertStringContainsString('data-volt-hook-log', $response->content());
    }

    public function test_cache_example_screen_renders_declared_navigation_and_invalidation_sections(): void
    {
        $response = $this->handleSkeletonRequest('/cacheExample');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Runtime Cache Demo', $response->content());
        self::assertStringContainsString('Recarga controlada', $response->content());
        self::assertStringContainsString('volt:cache-hit', $response->content());
        self::assertStringContainsString("volt:navigation-cache-invalidate", $response->content());
    }

    public function test_cache_example_screen_includes_extra_built_assets_only_for_that_route(): void
    {
        $home = $this->handleSkeletonRequest('/');
        $cacheExample = $this->handleSkeletonRequest('/cacheExample');

        self::assertSame(200, $home->statusCode(), $home->content());
        self::assertSame(200, $cacheExample->statusCode(), $cacheExample->content());
        self::assertSame(1, substr_count($home->content(), '<script type="module" src="/build/assets/'));
        self::assertSame(1, substr_count($home->content(), '<link rel="stylesheet" href="/build/assets/'));
        self::assertGreaterThanOrEqual(2, substr_count($cacheExample->content(), '<script type="module" src="/build/assets/'));
        self::assertGreaterThanOrEqual(2, substr_count($cacheExample->content(), '<link rel="stylesheet" href="/build/assets/'));
    }

    public function test_fragment_cache_screen_exposes_preserve_targets_and_fragment_monitors(): void
    {
        $response = $this->handleSkeletonRequest('/fragmentCache');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Cache Demo', $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="preserve"', $response->content());
        self::assertStringContainsString('data-volt-preserve="draft-fragment"', $response->content());
        self::assertStringContainsString('data-volt-preserve="live-shell"', $response->content());
        self::assertStringContainsString('volt:fragment-preserve', $response->content());
        self::assertStringContainsString('volt:fragment-discard', $response->content());
        self::assertStringContainsString('/formExample', $response->content());
        self::assertStringContainsString('/fragmentCacheReset', $response->content());
    }

    public function test_form_example_screen_exposes_matching_preserve_targets_for_reuse(): void
    {
        $response = $this->handleSkeletonRequest('/formExample');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="preserve"', $response->content());
        self::assertStringContainsString('data-volt-preserve="draft-fragment"', $response->content());
        self::assertStringContainsString('data-volt-preserve="live-shell"', $response->content());
        self::assertStringContainsString('volt:fragment-preserve', $response->content());
        self::assertStringContainsString('volt:fragment-discard', $response->content());
    }

    public function test_fragment_cache_reset_screen_declares_documental_reset_policy_for_preserved_fragments(): void
    {
        $response = $this->handleSkeletonRequest('/fragmentCacheReset');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Cache Reset', $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="reset"', $response->content());
        self::assertStringContainsString('<meta name="volt-cache-control" content="no-store"', $response->content());
        self::assertStringContainsString('data-volt-preserve="draft-fragment"', $response->content());
        self::assertStringContainsString('data-volt-preserve="live-shell"', $response->content());
        self::assertStringContainsString('documento impide reutilizar el nodo anterior', $response->content());
    }

    public function test_fragment_cache_manual_validation_routes_expose_control_and_discard_expectations(): void
    {
        $origin = $this->handleSkeletonRequest('/fragmentCache');
        $compatibleTarget = $this->handleSkeletonRequest('/formExample');
        $resetTarget = $this->handleSkeletonRequest('/fragmentCacheReset');

        self::assertSame(200, $origin->statusCode(), $origin->content());
        self::assertSame(200, $compatibleTarget->statusCode(), $compatibleTarget->content());
        self::assertSame(200, $resetTarget->statusCode(), $resetTarget->content());

        self::assertStringContainsString('Este contenido deberia resetearse.', $origin->content());
        self::assertStringContainsString('Probar descarte en /fragmentCacheReset', $origin->content());
        self::assertStringContainsString('Este contenido deberia resetearse.', $compatibleTarget->content());
        self::assertStringContainsString('Volver a fragment cache', $compatibleTarget->content());
        self::assertStringContainsString('document-policy', $resetTarget->content());
        self::assertStringContainsString('impide reutilizar el nodo anterior', $resetTarget->content());
    }

    public function test_request_lab_screen_exposes_explicit_abort_and_stale_controls(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeRequestLab');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Abort previous action', $response->content());
        self::assertStringContainsString('Abort previous navigation', $response->content());
        self::assertStringContainsString('Stale navigation', $response->content());
        self::assertStringContainsString('Actions POST', $response->content());
        self::assertStringContainsString('data-runtime-check="navigation-retry-policy-card"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-retry-policy-value"', $response->content());
        self::assertStringContainsString('data-runtime-check="navigation-retry-policy-value"', $response->content());
        self::assertStringContainsString('Retry navigation once', $response->content());
        self::assertStringContainsString('Conectividad del navegador', $response->content());
        self::assertStringContainsString('Panel unificado de resiliencia', $response->content());
        self::assertStringContainsString('volt:request-abort', $response->content());
        self::assertStringContainsString('data-runtime-check="action-endpoint-status"', $response->content());
        self::assertStringContainsString('data-runtime-check="network-status-label"', $response->content());
        self::assertStringContainsString('data-runtime-check="request-last-event"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-retry-contract"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-retry-network"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-retry-timeout"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-retry-protocol"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-outcome-contract"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-outcome-scope"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-outcome-retry"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-outcome-next-step"', $response->content());
        self::assertStringContainsString('data-runtime-check="action-outcome-summary"', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.retrySummaryStorageKey = \'volt.requestLab.lastNavigationRetry\';', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.navigationLifecycleStorageKey = \'volt.requestLab.lastNavigationLifecycle\';', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.resilienceSummaryStorageKey = \'volt.requestLab.lastResilienceSummary\';', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.resilienceHistoryStorageKey = \'volt.requestLab.resilienceHistory\';', $response->content());
        self::assertStringContainsString('data-volt-head-key="runtime-request-lab-spa-bridge"', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLabSpaBridgeInstalled', $response->content());
        self::assertStringContainsString('data-runtime-request-lab-bootstrap="true"', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.renderResiliencePanel = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.recordResilienceIncident = function(eventName, meta, outcome, target) {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.clearResiliencePanel = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.renderRetrySummaryCard = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.renderNavigationLifecycleSummaryCard = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.renderActionOutcomeContract = function(payload) {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.runAbortNavigationScenario = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.readRetrySummary = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.readNavigationLifecycleSummary = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.renderNavigationLifecycleSummaryCard();', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.runRetryNavigationScenario = function() {', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.handleNavigationLifecycleEvent = function(event) {', $response->content());
        self::assertStringContainsString('sessionStorage.setItem(', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-current-scenario"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-network-error"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-timeout"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-protocol-error"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-event"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-classification"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-contract"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-message"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-contract-abort-card"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-contract-abort-summary"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-contract-stale-card"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-contract-stale-summary"', $response->content());
        self::assertStringContainsString('window.__spaLabRequestLab.setBrokenActionEndpoint', $response->content());
        self::assertStringContainsString("window.addEventListener('volt:request-stale', window.__spaLabRequestLab.handleNavigationLifecycleEvent);", $response->content());
        self::assertStringContainsString("window.addEventListener('offline', window.__spaLabRequestLab.syncNetworkStatus);", $response->content());
        self::assertStringContainsString('/runtimeRequestLabSlow', $response->content());
        self::assertStringContainsString("summary.eventName === 'volt:request-abort'", $response->content());
        self::assertStringContainsString("summary.eventName === 'volt:request-stale'", $response->content());
        self::assertStringContainsString("summary.eventName === 'volt:request-retry'", $response->content());
        self::assertStringContainsString("if (meta.type === 'action') {", $response->content());
        self::assertStringContainsString("outcome === 'network-error'", $response->content());
        self::assertStringContainsString("outcome === 'timeout'", $response->content());
        self::assertStringContainsString("outcome === 'protocol-error'", $response->content());
        self::assertStringContainsString("retry: '0 automatico'", $response->content());
    }

    public function test_runtime_focus_screens_expose_focus_selection_and_scroll_contract_markers(): void
    {
        $origin = $this->handleSkeletonRequest('/runtimeFocus');
        $alt = $this->handleSkeletonRequest('/runtimeFocusAlt');

        self::assertSame(200, $origin->statusCode(), $origin->content());
        self::assertSame(200, $alt->statusCode(), $alt->content());
        self::assertStringContainsString('Contrato de patch, seleccion y scroll', $origin->content());
        self::assertStringContainsString('volt-click="refreshPatchProbe"', $origin->content());
        self::assertStringContainsString('data-volt-target="focus-patch-button"', $origin->content());
        self::assertStringContainsString('data-volt-target="focus-scroll-box"', $origin->content());
        self::assertStringContainsString('data-volt-preserve-scroll', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-patch-sequence"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-patch-summary"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-patch-request-marker"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-active-element"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-selection-range"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-selection-direction"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-selection-scroll-top"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-scroll-box-top"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-scroll-box-left"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-inspector-reason"', $origin->content());
        self::assertStringContainsString('Contrato de navegacion con preserve scroll', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-nav-reset-link"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-nav-preserve-link"', $origin->content());
        self::assertStringContainsString('data-runtime-check="focus-navigation-preserve-scroll-notes"', $origin->content());
        self::assertStringContainsString('volt:preserve-scroll', $origin->content());
        self::assertStringContainsString('window.__voltRuntimeFocusDemoInstalled', $origin->content());
        self::assertStringContainsString('href="/runtimeFocusAlt"', $origin->content());

        self::assertStringContainsString('volt:focus="shared:focus.returnAction"', $alt->content());
        self::assertStringContainsString('volt:autofocus.when="shared:focus.showErrors"', $alt->content());
        self::assertStringContainsString('Longitud controlada para preserve scroll', $alt->content());
        self::assertStringContainsString('data-runtime-check="focus-alt-reset-scroll-link"', $alt->content());
        self::assertStringContainsString('data-runtime-check="focus-alt-preserve-scroll-link"', $alt->content());
        self::assertStringContainsString('volt:preserve-scroll', $alt->content());
        self::assertStringContainsString('window.__voltRuntimeFocusDemoInstalled', $alt->content());
        self::assertStringContainsString('href="/runtimeFocus"', $alt->content());
    }

    public function test_runtime_events_screen_exposes_hook_inspector_cards_and_recent_log(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeEvents');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Hooks en vivo del frontend reactivo', $response->content());
        self::assertStringContainsString('Ir a RequestLab', $response->content());
        self::assertStringContainsString('data-runtime-check="events-session-incidents-badge"', $response->content());
        self::assertStringContainsString('data-runtime-check="events-session-incidents-detail"', $response->content());
        self::assertStringContainsString('Resumen persistido de navegacion', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-event"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-outcome"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-target"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-lifecycle-final-url"', $response->content());
        self::assertStringContainsString('Diagnostico de click y scroll', $response->content());
        self::assertStringContainsString('data-runtime-navigation-debug', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-stage"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-outcome"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-request-id"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-click-href"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-click-text"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-scroll-before"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-scroll-after"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-location"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-updated-at"', $response->content());
        self::assertStringContainsString('data-runtime-check="nav-debug-detail"', $response->content());
        self::assertStringContainsString('window.__spaLabNavigationDebug', $response->content());
        self::assertStringContainsString('Panel unificado de resiliencia', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-current-scenario"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-network-error"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-timeout"', $response->content());
        self::assertStringContainsString('data-runtime-check="resilience-scenario-protocol-error"', $response->content());
        self::assertStringContainsString('Contrato global <code>busy</code>', $response->content());
        self::assertStringContainsString('window.Volt.busy.current()', $response->content());
        self::assertStringContainsString('data-runtime-busy-panel', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-kind"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-phase"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-request"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-mirror"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-action"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-component"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-current-target"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-document-kind"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-last-active-summary"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-last-action-summary"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-last-event"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-updated-at"', $response->content());
        self::assertStringContainsString('data-runtime-check="busy-detail"', $response->content());
        self::assertStringContainsString('window.__spaLabRuntimeBusyPanel', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:before-patch"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:after-patch"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:before-effect"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:after-effect"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:before-navigate"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:navigated"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:before-enter"', $response->content());
        self::assertStringContainsString('data-volt-hook-card="volt:after-enter"', $response->content());
        self::assertStringContainsString('data-volt-hook-log', $response->content());
    }

    public function test_runtime_events_screen_exposes_efficiency_panels_and_runtime_navigation_links(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeEvents');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('data-runtime-efficiency-demo', $response->content());
        self::assertStringContainsString('window.Volt.telemetry.summary()', $response->content());
        self::assertStringContainsString('window.Volt.components.summary()', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-navigation-performance"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-runtime-asset"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-runtime-overview"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-budget-boot"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-budget-patch"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-budget-payload"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-budget-buffer"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-budget-summary"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-summary-json"', $response->content());
        self::assertStringContainsString('data-runtime-check="efficiency-components-detail"', $response->content());
        self::assertStringContainsString('Resetear telemetria', $response->content());
        self::assertStringContainsString('Refrescar roots', $response->content());
        self::assertStringContainsString('Budget boot', $response->content());
        self::assertStringContainsString('Budget patch visual', $response->content());
        self::assertStringContainsString('Budget payload action', $response->content());
        self::assertStringContainsString('Budget buffer runtime', $response->content());
        self::assertStringContainsString('Telemetry navigation', $response->content());
        self::assertStringContainsString('Telemetry action', $response->content());
        self::assertStringContainsString('Telemetry patch', $response->content());
        self::assertStringContainsString('Latest patch entry', $response->content());
        self::assertStringContainsString('/runtimeAdvancedDirectives', $response->content());
        self::assertStringContainsString('/runtimeState', $response->content());
        self::assertStringContainsString('/runtimeModelSync', $response->content());
    }

    public function test_runtime_events_screen_exposes_efficiency_status_and_latest_snapshots(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeEvents');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('data-volt-efficiency-status', $response->content());
        self::assertStringContainsString('boot', $response->content());
        self::assertStringContainsString('data-volt-efficiency-last-updated', $response->content());
        self::assertStringContainsString('(pendiente)', $response->content());
        self::assertStringContainsString('window.Volt.telemetry.latest()', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-overall', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-summary', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-boot-status', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-boot-value', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-patch-status', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-patch-value', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-payload-status', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-payload-value', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-buffer-status', $response->content());
        self::assertStringContainsString('data-volt-efficiency-budget-buffer-value', $response->content());
        self::assertStringContainsString('Resumen contractual de budgets', $response->content());
        self::assertStringContainsString('data-volt-efficiency-latest="navigation"', $response->content());
        self::assertStringContainsString('data-volt-efficiency-latest="action"', $response->content());
        self::assertStringContainsString('data-volt-efficiency-latest="patch"', $response->content());
        self::assertGreaterThanOrEqual(3, substr_count($response->content(), '(sin datos)'));
        self::assertStringContainsString('Runtime summary snapshot', $response->content());
        self::assertStringContainsString('Active components summary', $response->content());
    }

    public function test_runtime_matrix_screen_exposes_runner_context_coverage_and_budget_controls(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeMatrix');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Runtime Matrix', $response->content());
        self::assertStringContainsString('data-runtime-matrix-page', $response->content());
        self::assertStringContainsString('window.__spaLabRuntimeMatrixInstalled', $response->content());
        self::assertStringContainsString('data-runtime-matrix="scenario"', $response->content());
        self::assertStringContainsString('<option value="boot">boot</option>', $response->content());
        self::assertStringContainsString('<option value="spa">navegacion-spa</option>', $response->content());
        self::assertStringContainsString('<option value="action">action-reactiva</option>', $response->content());
        self::assertStringContainsString('<option value="model.sync">volt-model-sync</option>', $response->content());
        self::assertStringContainsString('<option value="large-list">listas-grandes</option>', $response->content());
        self::assertStringContainsString('<option value="cache">cache</option>', $response->content());
        self::assertStringContainsString('<option value="long-session">sesion-larga</option>', $response->content());
        self::assertStringContainsString('data-runtime-matrix="condition"', $response->content());
        self::assertStringContainsString('<option value="degradada">degradada</option>', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-payload-bytes"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-navigation-payload-bytes"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-large-list-patch-ms"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-large-list-payload-bytes"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-cache-hit-ratio-min"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-cache-dup-misses-max"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="budget-heap-used-max-bytes"', $response->content());
        self::assertStringContainsString('Exportar corridas (JSON)', $response->content());
        self::assertStringContainsString('Limpiar historial', $response->content());
        self::assertStringContainsString('data-runtime-matrix="coverage-summary"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="coverage-alerts"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="coverage-pending"', $response->content());
        self::assertStringContainsString('data-runtime-matrix="coverage-body"', $response->content());
        self::assertStringContainsString('Historial (últimas 30)', $response->content());
        self::assertStringContainsString('data-runtime-matrix="runs-body"', $response->content());
        self::assertStringContainsString('payload nav (bytes)', $response->content());
        self::assertStringContainsString('harness reproducible del', $response->content());
        self::assertStringContainsString('/runtimeRequestLab', $response->content());
        self::assertStringContainsString('/runtimeModelSync', $response->content());
        self::assertStringContainsString('/runtimeEvents', $response->content());
        self::assertStringContainsString('/runtimeLargeList', $response->content());
        self::assertStringContainsString('/runtimeMatrix', $response->content());
    }

    public function test_runtime_matrix_screen_persists_versioned_config_carryover_and_separated_payload_budgets(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeMatrix');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('const CONFIG_VERSION = 2;', $response->content());
        self::assertStringContainsString("const CONFIG_KEY = 'volt.runtimeMatrix.config';", $response->content());
        self::assertStringContainsString("const CARRYOVER_KEY = 'volt.runtimeMatrix.carryover';", $response->content());
        self::assertStringContainsString("const DEGRADATION_KEY = 'volt.runtimeMatrix.degradation';", $response->content());
        self::assertStringContainsString('payloadActionBytes: 2 * 1024,', $response->content());
        self::assertStringContainsString('navigationPayloadBytes: 50 * 1024,', $response->content());
        self::assertStringContainsString('payloadLargeListBytes: 256 * 1024,', $response->content());
        self::assertStringContainsString('cacheHitRatioMinPercent: 80,', $response->content());
        self::assertStringContainsString('cacheDuplicateMissesMax: 0,', $response->content());
        self::assertStringContainsString("spa: ['patch', 'navigationPayload', 'telemetry'],", $response->content());
        self::assertStringContainsString("action: ['patch', 'payloadAction', 'telemetry'],", $response->content());
        self::assertStringContainsString("'model.sync': ['patch', 'payloadAction', 'telemetry'],", $response->content());
        self::assertStringContainsString("'large-list': ['largeListPatch', 'largeListPayload', 'telemetry'],", $response->content());
        self::assertStringContainsString("cache: ['cacheHitRatio', 'cacheDupMisses'],", $response->content());
        self::assertStringContainsString("'long-session': ['telemetry', 'heapUsed', 'cacheHitRatio', 'cacheDupMisses'],", $response->content());
        self::assertStringContainsString('function normalizeConfig(saved) {', $response->content());
        self::assertStringContainsString('const isLegacyConfig = normalized.version !== CONFIG_VERSION;', $response->content());
        self::assertStringContainsString('function ensureConfigIsCurrent() {', $response->content());
        self::assertStringContainsString("telemetrySource: useCarryover ? 'carryover' : 'current-page',", $response->content());
        self::assertStringContainsString('thresholdBytes: budgetsConfig.navigationPayloadBytes,', $response->content());
        self::assertStringContainsString('status: classify(navigationPayloadBytes, budgetsConfig.navigationPayloadBytes, \'lte\'),', $response->content());
        self::assertStringContainsString('writeJsonStorage(CONFIG_KEY, config);', $response->content());
    }

    public function test_runtime_state_routes_document_client_scope_reset_and_shared_scope_survival(): void
    {
        $origin = $this->handleSkeletonRequest('/runtimeState');
        $destination = $this->handleSkeletonRequest('/runtimeStateAlt');

        self::assertSame(200, $origin->statusCode(), $origin->content());
        self::assertSame(200, $destination->statusCode(), $destination->content());
        self::assertStringContainsString('<meta name="volt-navigation-mode" content="auto"', $origin->content());
        self::assertStringContainsString('State Demo', $origin->content());
        self::assertStringContainsString('data-volt-state-example', $origin->content());
        self::assertStringContainsString('window.Volt.state', $origin->content());
        self::assertStringContainsString('captureSelectiveSync', $origin->content());
        self::assertStringContainsString('Paso 1. Guardar en el store runtime', $origin->content());
        self::assertStringContainsString('Paso 2. Confirmar el preview live', $origin->content());
        self::assertStringContainsString('Paso 3. Enviar lo ya guardado', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-sync-client-store-preview"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-sync-shared-store-preview"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-sync-shared-counter-preview"', $origin->content());
        self::assertStringContainsString('Estados runtime: <code>dirty</code>, <code>success</code>, <code>error</code>', $origin->content());
        self::assertStringContainsString('volt:dirty.target="statusProbeTitle"', $origin->content());
        self::assertStringContainsString('volt:dirty.debounce="200ms"', $origin->content());
        self::assertStringContainsString('volt:success="saveStatusProbe"', $origin->content());
        self::assertStringContainsString('volt:success.target="state-status-form"', $origin->content());
        self::assertStringContainsString('volt:error="failStatusProbe"', $origin->content());
        self::assertStringContainsString('volt:error.target="state-status-error-button"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-request-status"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-dirty-target"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-success-action"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-success-target"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-error-action"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-error-target"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-error-message"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-last-request-event"', $origin->content());
        self::assertStringContainsString('data-runtime-check="state-status-saved-message"', $origin->content());
        self::assertStringContainsString('Guardar y disparar success', $origin->content());
        self::assertStringContainsString('Forzar error controlado', $origin->content());
        self::assertStringContainsString("volt:text=\"client:draft.note ?? '(vacio)'\"", $origin->content());
        self::assertStringContainsString('Enviar al backend lo ya guardado en el store', $origin->content());
        self::assertStringContainsString('shared:serverSync.syncedAt', $origin->content());
        self::assertStringContainsString('volt:show="client:ui.showClientPanel"', $origin->content());
        self::assertStringContainsString('volt:if="shared:ui.mountSharedPanel"', $origin->content());
        self::assertStringContainsString('/runtimeStateAlt', $origin->content());
        self::assertStringContainsString('State Destination', $destination->content());
        self::assertStringContainsString('client snapshot', $destination->content());
        self::assertStringContainsString('shared snapshot', $destination->content());
        self::assertStringContainsString('Client scope', $destination->content());
        self::assertStringContainsString('/runtimeState', $destination->content());
    }

    public function test_runtime_advanced_directives_screen_exposes_stable_markers_for_contract_checks(): void
    {
        $response = $this->handleSkeletonRequest('/runtimeAdvancedDirectives');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Advanced Directives Demo', $response->content());
        self::assertStringContainsString('data-runtime-check="text-fallback-result"', $response->content());
        self::assertStringContainsString('data-runtime-check="show-compound-panel"', $response->content());
        self::assertStringContainsString('data-runtime-check="if-compound-panel"', $response->content());
        self::assertStringContainsString('data-runtime-check="class-multi-card"', $response->content());
        self::assertStringContainsString('data-runtime-check="attr-multi-button"', $response->content());
        self::assertStringContainsString('data-runtime-check="style-multi-card"', $response->content());
        self::assertStringContainsString("client:draft.note ?? shared:draft.note ?? 'Sin nota disponible'", $response->content());
        self::assertStringContainsString('/runtimeState', $response->content());
    }

    public function test_request_lab_retry_once_source_documents_safe_get_retry_contract(): void
    {
        $pagePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'spa-lab'
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Pages'
            . DIRECTORY_SEPARATOR . 'Request'
            . DIRECTORY_SEPARATOR . 'RequestLabRetryOncePage.php';
        $response = $this->handleSkeletonRequest('/runtimeRequestLab');

        $pageSource = file_get_contents($pagePath);

        self::assertIsString($pageSource);
        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString("storage_path('framework/cache/runtime-request-lab-retry-once.flag')", $pageSource);
        self::assertStringContainsString("throw new RuntimeException('Runtime QA forced transient navigation error.')", $pageSource);
        self::assertStringContainsString("@unlink(\$markerPath);", $pageSource);
        self::assertStringContainsString("request-retry-", $pageSource);
        self::assertStringContainsString("/runtimeRequestLabRetryOnce", $response->content());
    }

    public function test_request_lab_retry_once_target_can_render_when_marker_is_primed(): void
    {
        $markerPath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'runtime-request-lab-retry-once.flag';

        file_put_contents($markerPath, (string) time());

        $response = $this->handleSkeletonRequest('/runtimeRequestLabRetryOnce');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('<meta name="volt-navigation-mode" content="auto"', $response->content());
        self::assertStringContainsString('Request Lab Retry Once', $response->content());
        self::assertStringContainsString('Resumen visible del retry', $response->content());
        self::assertStringContainsString('data-runtime-check="retry-summary-event"', $response->content());
        self::assertStringContainsString('data-runtime-check="retry-summary-attempt"', $response->content());
        self::assertStringContainsString('data-runtime-check="retry-summary-status"', $response->content());
        self::assertStringContainsString("var storageKey = 'volt.requestLab.lastNavigationRetry';", $response->content());
        self::assertStringContainsString('finalUrl = ', $response->content());
        self::assertStringContainsString('falla una vez con error servidor', $response->content());
        self::assertStringContainsString('request-retry-', $response->content());
        self::assertStringContainsString('/runtimeRequestLab', $response->content());

        if (is_file($markerPath)) {
            @unlink($markerPath);
        }
    }

    public function test_runtime_persist_origin_screen_exposes_persist_targets_and_status_panel(): void
    {
        $response = $this->handleSkeletonRequest('/runtimePersist');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Persist MVP', $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="preserve"', $response->content());
        self::assertStringContainsString('window.__voltPersistDemoState = window.__voltPersistDemoState || {', $response->content());
        self::assertStringContainsString('window.__voltPersistDemoState.lastNavigatedDetail =', $response->content());
        self::assertStringContainsString('data-volt-persist="persist-sidebar"', $response->content());
        self::assertStringContainsString('volt:persist="persist-player"', $response->content());
        self::assertStringContainsString('data-volt-persist-status', $response->content());
        self::assertStringContainsString('/runtimePersistBridge', $response->content());
        self::assertStringContainsString('/runtimePersistAlt', $response->content());
    }

    public function test_runtime_persist_bridge_screen_documents_registry_survival_without_targets(): void
    {
        $response = $this->handleSkeletonRequest('/runtimePersistBridge');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Persist Bridge', $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="preserve"', $response->content());
        self::assertStringContainsString('window.__voltPersistDemoState = window.__voltPersistDemoState || {', $response->content());
        self::assertStringContainsString('window.__voltPersistDemoState.lastNavigatedDetail =', $response->content());
        self::assertStringContainsString('persistentFragmentRegistrySize', $response->content());
        self::assertStringContainsString('persistedFragments', $response->content());
        self::assertStringContainsString('/runtimePersistAlt', $response->content());
    }

    public function test_runtime_persist_destination_screen_exposes_reinjection_targets(): void
    {
        $response = $this->handleSkeletonRequest('/runtimePersistAlt');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Persist Destination', $response->content());
        self::assertStringContainsString('<meta name="volt-fragment-control" content="preserve"', $response->content());
        self::assertStringContainsString('window.__voltPersistDemoState.lastNavigatedDetail =', $response->content());
        self::assertStringContainsString('data-volt-persist="persist-sidebar"', $response->content());
        self::assertStringContainsString('data-volt-persist="persist-player"', $response->content());
        self::assertStringContainsString('persistedFragments &gt; 0', $response->content());
        self::assertStringContainsString('Registry size', $response->content());
    }

    public function test_traditional_controller_view_can_embed_an_interactive_island(): void
    {
        $response = $this->handleSkeletonRequest('/islandExample');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Controller + View + Isla Interactiva', $response->content());
        self::assertStringContainsString('data-volt-root="true"', $response->content());
        self::assertStringContainsString('data-volt-component="App\\View\\Components\\IslandCounter"', $response->content());
        self::assertStringContainsString('volt:click="increment"', $response->content());
        self::assertStringContainsString('data-volt-layout="app"', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-runtime="true"'));
        self::assertStringContainsString(
            '&quot;meta&quot;:{&quot;route&quot;:{&quot;name&quot;:&quot;islandExample&quot;',
            $response->content(),
        );
    }

    public function test_island_example_emits_spa_navigation_payload(): void
    {
        $navigation = $this->handleSkeletonNavigationRequest('/islandExample');
        $payload = $this->decodeNavigationPayload($navigation);

        self::assertSame(200, $navigation->statusCode(), $navigation->content());
        self::assertSame('/islandExample', $payload['navigation']['target'] ?? null);
        self::assertSame('islandExample', $payload['screen']['route'] ?? null);
    }

    public function test_traditional_controller_view_without_layout_is_still_spa_capable(): void
    {
        $response = $this->handleSkeletonRequest('/noLayoutExample');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Vista Tradicional Sin Layout', $response->content());
        self::assertStringContainsString('href="/"', $response->content());
        self::assertStringContainsString('href="/islandExample"', $response->content());
        self::assertStringContainsString('volt:navigate', $response->content());
        self::assertStringContainsString('data-volt-document="spa"', $response->content());
        self::assertStringContainsString('data-volt-navigation-mode="auto"', $response->content());
        self::assertStringNotContainsString('data-volt-layout=', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-runtime="true"'));
    }

    public function test_no_layout_example_emits_spa_navigation_payload_without_runtime_layout_hint(): void
    {
        $navigation = $this->handleSkeletonNavigationRequest('/noLayoutExample');
        $payload = $this->decodeNavigationPayload($navigation);

        self::assertSame(200, $navigation->statusCode(), $navigation->content());
        self::assertSame('/noLayoutExample', $payload['navigation']['target'] ?? null);
        self::assertSame('noLayoutExample', $payload['screen']['route'] ?? null);
        self::assertNull($payload['policy']['document'] ?? null);
        self::assertNull($payload['policy']['navigation'] ?? null);
        self::assertNull($payload['runtime']['layout'] ?? null);
        self::assertStringContainsString('data-volt-document="spa"', $navigation->content());
        self::assertStringContainsString('data-volt-navigation-mode="auto"', $navigation->content());
    }

    public function test_routing_lab_navigation_payload_exposes_reload_and_redirect_contracts(): void
    {
        $reload = $this->handleSkeletonNavigationRequest('/routing-lab/reports/export');
        $reloadPayload = $this->decodeNavigationPayload($reload);
        $redirect = $this->handleSkeletonNavigationRequest('/routing-lab/private');
        $redirectPayload = $this->decodeNavigationPayload($redirect);

        self::assertSame(200, $reload->statusCode(), $reload->content());
        self::assertSame('/routing-lab/reports/export', $reloadPayload['navigation']['target'] ?? null);
        self::assertSame('routing.lab.reports.export', $reloadPayload['screen']['route'] ?? null);
        self::assertSame('reload', $reloadPayload['policy']['document'] ?? null);
        self::assertSame('reload', $reloadPayload['policy']['navigation'] ?? null);
        self::assertSame('routing-lab', $reloadPayload['runtime']['layout'] ?? null);
        self::assertSame('soft-edge', $reloadPayload['runtime']['transition'] ?? null);
        self::assertFalse($reloadPayload['runtime']['hydrate'] ?? true);

        self::assertSame(302, $redirect->statusCode(), $redirect->content());
        self::assertSame('/login', $redirectPayload['navigation']['target'] ?? null);
        self::assertSame('routing.lab.private', $redirectPayload['screen']['route'] ?? null);
        self::assertSame([
            'location' => '/login',
            'status' => 302,
        ], $redirectPayload['redirect'] ?? null);
        self::assertSame('routing-lab', $redirectPayload['runtime']['layout'] ?? null);
    }

    public function test_skeleton_layout_emits_stable_head_and_layout_markers_for_routing_lab(): void
    {
        $response = $this->handleSkeletonRequest('/routing-lab');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('<meta charset="UTF-8" data-volt-head-key="document-charset">', $response->content());
        self::assertStringContainsString(
            '<meta name="viewport" content="width=device-width, initial-scale=1.0" data-volt-head-key="document-viewport">',
            $response->content(),
        );
        self::assertStringContainsString('<body class="bg-slate-950 text-slate-100"', $response->content());
        self::assertStringContainsString('data-volt-document="spa"', $response->content());
        self::assertStringContainsString('data-volt-navigation-mode="auto"', $response->content());
        self::assertStringContainsString('data-volt-layout="routing-lab"', $response->content());
        self::assertStringContainsString('data-volt-hydrate="false"', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-runtime="true"'));
        self::assertMatchesRegularExpression('/<script data-volt-runtime="true" src="\/_volt\/runtime\.js\?v=\d+" defer><\/script>/', $response->content());
    }

    public function test_routing_lab_user_screen_exposes_manifest_and_runtime_expectations(): void
    {
        $response = $this->handleSkeletonRequest('/routing-lab/users/15');
        $navigation = $this->handleSkeletonNavigationRequest('/routing-lab/users/15');
        $payload = $this->decodeNavigationPayload($navigation);

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Usuario 15', $response->content());
        self::assertStringContainsString('routing.lab.users.show', $response->content());
        self::assertStringContainsString('/_volt/routes-manifest.json', $response->content());
        self::assertStringContainsString('path = /routing-lab/users/{user}', $response->content());
        self::assertStringContainsString('data-volt-layout="routing-lab"', $response->content());
        self::assertStringContainsString('data-volt-runtime="true"', $response->content());

        self::assertSame(200, $navigation->statusCode(), $navigation->content());
        self::assertSame('/routing-lab/users/15', $payload['navigation']['target'] ?? null);
        self::assertSame('routing.lab.users.show', $payload['screen']['route'] ?? null);
        self::assertSame('spa', $payload['policy']['document'] ?? null);
        self::assertSame('auto', $payload['policy']['navigation'] ?? null);
        self::assertSame('routing-lab', $payload['runtime']['layout'] ?? null);
        self::assertSame('fade', $payload['runtime']['transition'] ?? null);
        self::assertTrue($payload['runtime']['hydrate'] ?? false);
    }

    public function test_routes_manifest_exposes_reload_policy_for_export_route(): void
    {
        $response = $this->handleSkeletonRequest('/_volt/routes-manifest.json');

        self::assertSame(200, $response->statusCode(), $response->content());

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest['routes'] ?? null);

        $match = null;

        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (! is_array($route)) {
                continue;
            }

            if (($route['name'] ?? null) === 'routing.lab.reports.export') {
                $match = $route;
                break;
            }
        }

        self::assertIsArray($match, 'Expected to find routing.lab.reports.export in the routes manifest.');
        self::assertSame('reload', $match['policy']['document'] ?? null);
        self::assertSame('reload', $match['policy']['navigation'] ?? null);
        self::assertSame('routing-lab', $match['runtime']['layout'] ?? null);
    }

    public function test_routes_manifest_exposes_spa_policy_for_head_samples(): void
    {
        $response = $this->handleSkeletonRequest('/_volt/routes-manifest.json');

        self::assertSame(200, $response->statusCode(), $response->content());

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest['routes'] ?? null);

        $matches = [];

        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (! is_array($route)) {
                continue;
            }

            $name = $route['name'] ?? null;

            if ($name === 'routing.lab.head.a' || $name === 'routing.lab.head.b') {
                $matches[$name] = $route;
            }
        }

        self::assertArrayHasKey('routing.lab.head.a', $matches);
        self::assertArrayHasKey('routing.lab.head.b', $matches);

        foreach (['routing.lab.head.a', 'routing.lab.head.b'] as $name) {
            self::assertSame('spa', $matches[$name]['policy']['document'] ?? null, $name);
            self::assertSame('auto', $matches[$name]['policy']['navigation'] ?? null, $name);
            self::assertSame('routing-lab', $matches[$name]['runtime']['layout'] ?? null, $name);
        }
    }

    public function test_routing_lab_login_screen_documents_redirect_contract(): void
    {
        $response = $this->handleSkeletonRequest('/login');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('Login de prueba', $response->content());
        self::assertStringContainsString('redirect.location = /login', $response->content());
        self::assertStringContainsString('href="/routing-lab/private"', $response->content());
        self::assertStringContainsString('data-volt-layout="routing-lab"', $response->content());
    }

    public function test_routing_lab_error_route_emits_spa_navigation_error_payload(): void
    {
        $response = $this->handleSkeletonNavigationRequest('/routing-lab/boom');
        $payload = $this->decodeNavigationPayload($response);

        self::assertSame(500, $response->statusCode());
        self::assertSame('/routing-lab/boom', $payload['navigation']['target'] ?? null);
        self::assertSame('routing.lab.boom', $payload['screen']['route'] ?? null);
        self::assertNull($payload['redirect'] ?? null);
        self::assertSame([
            'code' => 500,
            'message' => 'Server Error',
        ], $payload['error'] ?? null);
        self::assertStringContainsString('<body data-volt-document="reload" data-volt-layout="routing-lab">', $response->content());
    }

    public function test_runtime_asset_exposes_runtime_hooks_and_public_apis(): void
    {
        $response = $this->handleSkeletonRequest('/routing-lab');
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('/_volt/runtime.js?v=', $response->content());
        self::assertMatchesRegularExpression('/<script data-volt-runtime="true" src="\/_volt\/runtime\.js\?v=\d+" defer><\/script>/', $response->content());

        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertSame('application/javascript; charset=UTF-8', $runtimeAsset->headers()['Content-Type']);
        $this->assertRuntimeAssetContains('volt:request-finish', $runtimeAsset);
        $this->assertRuntimeAssetContains('volt:component-destroyed', $runtimeAsset);
        $this->assertRuntimeAssetContains('function cleanupRuntimeOrphans()', $runtimeAsset);
        $this->assertRuntimeAssetContains('navigationViewportTrackedElements:new Set', $runtimeAsset);
        $this->assertRuntimeAssetContains('busyState: {', $runtimeAsset);
        $this->assertRuntimeAssetContains('window.Volt.components={entries:activeComponentsEntries', $runtimeAsset);
        $this->assertRuntimeAssetContains('window.Volt.telemetry={entries:filteredTelemetryEntries', $runtimeAsset);
        $this->assertRuntimeAssetContains('window.Volt.busy={active:function(){return cloneBusyState(resolveGlobalBusyState()).active}', $runtimeAsset);
        $this->assertRuntimeAssetContains('window.Volt.directives=', $runtimeAsset);
        $this->assertRuntimeAssetContains('registerFrontendDirective', $runtimeAsset);
        $this->assertRuntimeAssetContains('emitRuntimeHook("volt:busy-change"', $runtimeAsset);
        $this->assertRuntimeAssetContains('emitRuntimeHook("volt:busy-start"', $runtimeAsset);
        $this->assertRuntimeAssetContains('emitRuntimeHook("volt:busy-end"', $runtimeAsset);
        $this->assertRuntimeAssetContains('data-volt-busy', $runtimeAsset);
        $this->assertRuntimeAssetContains('data-volt-runtime-busy-shell', $runtimeAsset);
        $this->assertRuntimeAssetContains('latest: function (options) {', $runtimeAsset);
        $this->assertRuntimeAssetContains('summary: telemetrySummary,', $runtimeAsset);
        $this->assertRuntimeAssetContains('snapshot: function (options) {', $runtimeAsset);
        $this->assertRuntimeAssetContains('reset: resetRuntimeTelemetry,', $runtimeAsset);
        $this->assertRuntimeAssetContains('summary: activeComponentsSummary,', $runtimeAsset);
        $this->assertRuntimeAssetContains('snapshot: activeComponentsSnapshot,', $runtimeAsset);
        $this->assertRuntimeAssetContains('refresh: function (reason) {', $runtimeAsset);
    }

    public function test_skeleton_html_resolves_built_manifest_assets_when_hot_reload_is_not_active(): void
    {
        $manifestPath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'build'
            . DIRECTORY_SEPARATOR . '.vite'
            . DIRECTORY_SEPARATOR . 'manifest.json';
        $response = $this->handleSkeletonRequest('/');

        self::assertFileExists($manifestPath);
        self::assertSame(200, $response->statusCode(), $response->content());
        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/', $response->content());
        self::assertStringContainsString('<script type="module" src="/build/assets/', $response->content());
        self::assertStringNotContainsString('@vite/client', $response->content());
    }

    public function test_runtime_source_reads_wrapped_component_document_meta_from_the_full_parsed_document(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '13-state-sync-navigation.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($navigationSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('typeof doc.querySelector === "function"', $navigationSource);
        self::assertStringContainsString('? doc.querySelector(selector)', $navigationSource);
        $this->assertRuntimeAssetContains('"function"==typeof doc.querySelector', $runtimeAsset);
        $this->assertRuntimeAssetContains('? doc.querySelector(selector)', $runtimeAsset);
    }

    public function test_runtime_source_can_preload_stylesheets_and_modules_from_prefetched_documents(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $cacheSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '20-navigation-cache.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($cacheSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('key: "style:" + href,', $cacheSource);
        self::assertStringContainsString('rel: "preload",', $cacheSource);
        self::assertStringContainsString('as: "style",', $cacheSource);
        $this->assertRuntimeAssetContains('key: "style:" + href,', $runtimeAsset);
        $this->assertRuntimeAssetContains('rel: "preload",', $runtimeAsset);
        $this->assertRuntimeAssetContains('as: "style",', $runtimeAsset);
    }

    public function test_runtime_source_only_falls_back_for_layout_changes_when_both_documents_declare_layouts(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationDocumentSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '42-navigation-document.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($navigationDocumentSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('if (!currentLayout || !nextLayout) {', $navigationDocumentSource);
        $this->assertRuntimeAssetContains('return!(!currentLayout||!nextLayout)&&currentLayout!==nextLayout', $runtimeAsset);
    }

    public function test_runtime_source_handles_popstate_with_a_spa_visit_and_reload_fallback(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($bootSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('window.addEventListener("popstate", function () {', $bootSource);
        self::assertStringContainsString('visit(window.location.href, {', $bootSource);
        self::assertStringContainsString('updateHistory: false,', $bootSource);
        self::assertStringContainsString('historyMode: "replace",', $bootSource);
        self::assertStringContainsString('preserveScroll: false,', $bootSource);
        self::assertStringContainsString('fallback: false,', $bootSource);
        self::assertStringContainsString('window.location.reload();', $bootSource);
        $this->assertRuntimeAssetContains('window.addEventListener("popstate",function(){visit(window.location.href,{', $runtimeAsset);
        $this->assertRuntimeAssetContains('visit(window.location.href, {', $runtimeAsset);
        $this->assertRuntimeAssetContains('fallback:!1}).catch(function(error){console.error("VoltStack navigation error:",error),window.location.reload()', $runtimeAsset);
    }

    public function test_runtime_source_reconciles_managed_head_entries_without_duplicating_scripts(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationDocumentSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '42-navigation-document.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($navigationDocumentSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('function managedHeadNodeKey(node) {', $navigationDocumentSource);
        self::assertStringContainsString('return "script:" + (node.getAttribute("type") || "") + ":" + src;', $navigationDocumentSource);
        self::assertStringContainsString('async function reconcileDocumentHead(nextHead) {', $navigationDocumentSource);
        self::assertStringContainsString('const existing = currentMap.get(entry.key);', $navigationDocumentSource);
        self::assertStringContainsString('syncManagedHeadNode(existing, entry.node);', $navigationDocumentSource);
        self::assertStringContainsString('const clone = entry.node.cloneNode(true);', $navigationDocumentSource);
        self::assertStringContainsString('document.head.appendChild(clone);', $navigationDocumentSource);
        $this->assertRuntimeAssetContains('return src?"script:"+(node.getAttribute("type")||"")+":"+src:null', $runtimeAsset);
        $this->assertRuntimeAssetContains('async function reconcileDocumentHead(nextHead){', $runtimeAsset);
        $this->assertRuntimeAssetContains('syncManagedHeadNode(existing,entry.node);', $runtimeAsset);
    }

    public function test_html_document_bootstrapper_source_hardens_inline_head_assets_with_stable_keys(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootstrapperSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Quantum'
            . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'HtmlDocumentBootstrapper.php'
        );

        self::assertIsString($bootstrapperSource);
        self::assertStringContainsString('private const HEAD_MANAGED_KEY_ATTRIBUTE = \'data-volt-head-key\';', $bootstrapperSource);
        self::assertStringContainsString('return $this->decorateManagedHeadKeys($content);', $bootstrapperSource);
        self::assertStringContainsString('private function decorateManagedHeadKeys(string $content): string', $bootstrapperSource);
        self::assertStringContainsString('private function decorateInlineHeadNodes(string $content, string $tag): string', $bootstrapperSource);
        self::assertStringContainsString('private function managedHeadKeyForInlineNode(string $tag, string $attributes, string $content): string', $bootstrapperSource);
        self::assertStringContainsString('\'auto-%s:%s\'', $bootstrapperSource);
        self::assertStringContainsString('sha1($tag . \'|\' . $this->normalizedHeadNodeAttributes($attributes) . \'|\' . $content)', $bootstrapperSource);
    }

    public function test_spa_routes_do_not_emit_unmanaged_inline_head_assets(): void
    {
        $routes = [
            '/',
            '/spaReactive',
            '/cacheExample',
            '/runtimeEvents',
            '/runtimeMatrix',
            '/runtimeLargeList',
            '/runtimeFocus',
            '/runtimeRequestLab',
        ];

        foreach ($routes as $route) {
            $response = $this->handleSkeletonRequest($route);

            self::assertSame(200, $response->statusCode(), sprintf('Route %s did not return 200.', $route));
            $this->assertHeadInlineAssetsAreKeyed($response->content(), $route);
        }
    }

    public function test_runtime_source_exposes_preserved_fragment_capture_restore_and_discard_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationDocumentSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '42-navigation-document.js'
        );
        $navigationStateSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '13-state-sync-navigation.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($navigationDocumentSource);
        self::assertIsString($navigationStateSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('function preservedFragmentAttribute(element) {', $navigationDocumentSource);
        self::assertStringContainsString('"data-volt-preserve",', $navigationDocumentSource);
        self::assertStringContainsString('"volt-preserve",', $navigationDocumentSource);
        self::assertStringContainsString('"volt:preserve",', $navigationDocumentSource);
        self::assertStringContainsString('function capturePreservedFragments(root, meta) {', $navigationDocumentSource);
        self::assertStringContainsString('function restorePreservedFragments(root, fragments, meta) {', $navigationDocumentSource);
        self::assertStringContainsString('"volt:fragment-preserve",', $navigationDocumentSource);
        self::assertStringContainsString('"volt:fragment-discard",', $navigationDocumentSource);
        self::assertStringContainsString('function fragmentControlForDocument(doc) {', $navigationStateSource);
        self::assertStringContainsString('control.mode = "reset";', $navigationStateSource);
        self::assertStringContainsString('const declaredMeta = firstDocumentMetaValue(', $navigationStateSource);
        $this->assertRuntimeAssetContains('"data-volt-preserve",', $runtimeAsset);
        $this->assertRuntimeAssetContains('"volt:fragment-preserve",', $runtimeAsset);
        $this->assertRuntimeAssetContains('"volt:fragment-discard",', $runtimeAsset);
        $this->assertRuntimeAssetContains('restorePreservedFragments(', $runtimeAsset);
    }

    public function test_runtime_source_exposes_persistent_fragment_capture_and_restore_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationDocumentSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '42-navigation-document.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($navigationDocumentSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('function persistedFragmentAttribute(element) {', $navigationDocumentSource);
        self::assertStringContainsString('"data-volt-persist",', $navigationDocumentSource);
        self::assertStringContainsString('"volt-persist",', $navigationDocumentSource);
        self::assertStringContainsString('"volt:persist",', $navigationDocumentSource);
        self::assertStringContainsString('function persistedFragmentKey(element) {', $navigationDocumentSource);
        self::assertStringContainsString('runtime.persistentFragments.set(key, fragment);', $navigationDocumentSource);
        self::assertStringContainsString('const targets = persistentFragmentTargets(root);', $navigationDocumentSource);
        self::assertStringContainsString('runtime.persistentFragments.delete(key);', $navigationDocumentSource);
        self::assertStringContainsString('persistentRegistrySize: runtime.persistentFragments.size,', $navigationDocumentSource);
        $this->assertRuntimeAssetContains('function persistedFragmentAttribute(element) {', $runtimeAsset);
        $this->assertRuntimeAssetContains('directiveAttribute(element,["data-volt-persist","volt-persist","volt:persist"])', $runtimeAsset);
        $this->assertRuntimeAssetContains('runtime.persistentFragments.set(key,{key:key,tagName:fragment.tagName,element:fragment.element})', $runtimeAsset);
        $this->assertRuntimeAssetContains('persistentRegistrySize:runtime.persistentFragments.size', $runtimeAsset);
    }

    public function test_runtime_source_falls_back_to_full_reload_when_navigation_returns_http_errors(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($visitSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('if (payload && payload.error && typeof payload.error === "object") {', $visitSource);
        self::assertStringContainsString('fallbackReason = settings.fallback !== false ? "request-error" : null;', $visitSource);
        self::assertStringContainsString('emitRuntimeHook("volt:request-error", errorDetail, document);', $visitSource);
        self::assertStringContainsString('window.location.assign(finalUrl);', $visitSource);
        self::assertStringContainsString('window.location.assign(normalizedUrl);', $visitSource);
        $this->assertRuntimeAssetContains('payload&&payload.error&&"object"==typeof payload.error', $runtimeAsset);
        $this->assertRuntimeAssetContains('emitRuntimeHook("volt:request-error",errorDetail,document)', $runtimeAsset);
        $this->assertRuntimeAssetContains('window.location.assign(finalUrl);', $runtimeAsset);
    }

    public function test_runtime_source_updates_snapshot_when_volt_model_inputs_change(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($bootSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('const snapshot = readSnapshot(root);', $bootSource);
        self::assertStringContainsString('const key = directiveValue(element, ["volt-model", "volt:model"]);', $bootSource);
        self::assertStringContainsString('if (snapshot && snapshot.state && key) {', $bootSource);
        self::assertStringContainsString('snapshot.state[key] =', $bootSource);
        self::assertStringContainsString('root.setAttribute("data-volt-snapshot", JSON.stringify(snapshot));', $bootSource);
        self::assertStringContainsString('updateModelSyncDirectiveFromElement(element, root, "directive:model.sync:input");', $bootSource);
        $this->assertRuntimeAssetContains('snapshot&&snapshot.state&&key&&(snapshot.state[key]=', $runtimeAsset);
        $this->assertRuntimeAssetContains('root.setAttribute("data-volt-snapshot",JSON.stringify(snapshot))', $runtimeAsset);
    }

    public function test_runtime_source_schedules_internal_sync_requests_for_volt_model_sync(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $modelDirectiveSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '11-dom-model-directives.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($modelDirectiveSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('function scheduleModelSyncDirectiveDispatch(root, element) {', $modelDirectiveSource);
        self::assertStringContainsString('MODEL_SYNC_INTERNAL_ACTION,', $modelDirectiveSource);
        self::assertStringContainsString('}, MODEL_SYNC_DEBOUNCE);', $modelDirectiveSource);
        self::assertStringContainsString('runtime.modelSyncDebounces.set(element, timeoutId);', $modelDirectiveSource);
        self::assertStringContainsString('runtime.modelSyncTrackedElements.add(element);', $modelDirectiveSource);
        $this->assertRuntimeAssetContains('dispatchAction(activeRoot,"__volt_sync__"', $runtimeAsset);
        $this->assertRuntimeAssetContains('runtime.modelSyncDebounces.set(element,timeoutId),runtime.modelSyncTrackedElements.add(element)', $runtimeAsset);
    }

    public function test_runtime_source_updates_snapshot_after_action_responses_and_emits_stale_abort_hooks(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $actionSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($actionSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('outcome = "stale";', $actionSource);
        self::assertStringContainsString('"volt:request-stale",', $actionSource);
        self::assertStringContainsString('outcome = "aborted";', $actionSource);
        self::assertStringContainsString('"volt:request-abort",', $actionSource);
        self::assertStringContainsString('if (payload.snapshot && updatedRoot) {', $actionSource);
        self::assertStringContainsString('"data-volt-snapshot",', $actionSource);
        self::assertStringContainsString('JSON.stringify(payload.snapshot),', $actionSource);
        $this->assertRuntimeAssetContains('"volt:request-stale",', $runtimeAsset);
        $this->assertRuntimeAssetContains('"volt:request-abort",', $runtimeAsset);
        $this->assertRuntimeAssetContains('payload.snapshot&&updatedRoot&&updatedRoot.setAttribute("data-volt-snapshot",JSON.stringify(payload.snapshot))', $runtimeAsset);
    }

    public function test_runtime_source_supports_reactive_actions_retry_policy_opt_in(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $actionSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );

        self::assertIsString($actionSource);
        self::assertStringContainsString('resolveRequestRetryPolicy("action"', $actionSource);
        self::assertStringContainsString('"volt:request-retry"', $actionSource);
        self::assertStringContainsString('waitForRetryDelay(', $actionSource);
    }

    public function test_runtime_source_keeps_spa_navigation_on_get_and_protocol_actions_on_post(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $actionSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );

        self::assertIsString($navigationSource);
        self::assertIsString($actionSource);
        self::assertStringContainsString('method: "GET"', $navigationSource);
        self::assertStringContainsString('"X-Volt-Navigate": "true"', $navigationSource);
        self::assertStringContainsString('method: "POST"', $actionSource);
        self::assertStringContainsString('"/_volt/action"', $actionSource);
    }

    public function test_runtime_source_only_preserves_document_scroll_when_navigation_requests_it(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($bootSource);
        self::assertIsString($visitSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('navigationTrigger.hasAttribute("volt-preserve-scroll") ||', $bootSource);
        self::assertStringContainsString('navigationTrigger.hasAttribute("volt:preserve-scroll");', $bootSource);
        self::assertStringContainsString('preserveScroll: preserveScroll,', $bootSource);
        self::assertStringContainsString('if (settings.preserveScroll !== true) {', $visitSource);
        self::assertStringContainsString('window.scrollTo(0, 0);', $visitSource);
        $this->assertRuntimeAssetContains('preserveScroll: preserveScroll,', $runtimeAsset);
        $this->assertRuntimeAssetContains('!0!==settings.preserveScroll&&window.scrollTo(0,0)', $runtimeAsset);
    }

    public function test_runtime_source_exposes_redirect_as_an_explicit_navigation_payload_field(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationCacheSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '20-navigation-cache.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );

        self::assertIsString($navigationCacheSource);
        self::assertIsString($visitSource);
        self::assertStringContainsString('redirect: redirectTarget,', $navigationCacheSource);
        self::assertStringContainsString('redirect: responseRedirect,', $navigationCacheSource);
        self::assertStringContainsString('payload && payload.redirect', $visitSource);
    }

    public function test_runtime_source_exposes_error_as_an_explicit_navigation_payload_field(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationCacheSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '20-navigation-cache.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );

        self::assertIsString($navigationCacheSource);
        self::assertIsString($visitSource);
        self::assertStringContainsString('navigationErrorPayload(response.status, response.statusText)', $visitSource);
        self::assertStringContainsString('payload.error =', $visitSource);
        self::assertStringContainsString('if (payload && payload.error && typeof payload.error === "object") {', $visitSource);
        self::assertStringContainsString('if (payload && payload.error && typeof payload.error === "object") {', $navigationCacheSource);
        self::assertStringContainsString('error: payload.error,', $visitSource);
    }

    public function test_runtime_source_retries_retryable_http_navigation_payload_errors_before_surface_them(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($visitSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());
        self::assertStringContainsString('const retryStatus =', $visitSource);
        self::assertStringContainsString('retryAttempt: attempt + 1,', $visitSource);
        self::assertStringContainsString('shouldRetryNavigationRequest(', $visitSource);
        self::assertStringContainsString('"volt:request-retry",', $visitSource);
        self::assertStringContainsString('continue;', $visitSource);
        $this->assertRuntimeAssetContains('retryAttempt:attempt+1', $runtimeAsset);
        $this->assertRuntimeAssetContains('"volt:request-retry",', $runtimeAsset);
    }

    public function test_runtime_source_exposes_target_as_an_explicit_navigation_payload_field(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $navigationCacheSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '20-navigation-cache.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );

        self::assertIsString($navigationCacheSource);
        self::assertIsString($visitSource);
        self::assertStringContainsString('target: entry.target || entry.url,', $navigationCacheSource);
        self::assertStringContainsString('target: payloadTarget,', $navigationCacheSource);
        self::assertStringContainsString('payload.target = normalizeNavigationUrl(spaNavigation.navigation.target);', $visitSource);
        self::assertStringContainsString('let navigationTarget = normalizedUrl;', $visitSource);
        self::assertStringContainsString('target: navigationTarget,', $visitSource);
    }

    public function test_runtime_source_exposes_frontend_directives_registry_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $registrySource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '11-directives-registry.js'
        );
        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );

        self::assertIsString($registrySource);
        self::assertIsString($bootSource);
        self::assertStringContainsString('function createPublicDirectivesApi()', $registrySource);
        self::assertStringContainsString('resolveValue: function (expression)', $registrySource);
        self::assertStringContainsString('resolveActive: function (expression)', $registrySource);
        self::assertStringContainsString('window.Volt.directives = createPublicDirectivesApi();', $bootSource);
    }

    public function test_runtime_source_exposes_public_on_api_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootstrapSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '00-bootstrap.js'
        );
        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );

        self::assertIsString($bootstrapSource);
        self::assertIsString($bootSource);
        self::assertStringContainsString('function createPublicOnFunction()', $bootstrapSource);
        self::assertStringContainsString('window.Volt.on = createPublicOnFunction();', $bootSource);
    }

    public function test_runtime_source_exposes_public_plugins_and_effects_contracts(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $pluginsSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '14-plugins-registry.js'
        );
        $effectsSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '43-effects-patch.js'
        );
        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );

        self::assertIsString($pluginsSource);
        self::assertIsString($effectsSource);
        self::assertIsString($bootSource);
        self::assertStringContainsString('function createPublicPluginsApi()', $pluginsSource);
        self::assertStringContainsString('function createPublicEffectsApi()', $effectsSource);
        self::assertStringContainsString('window.Volt.effects = createPublicEffectsApi();', $bootSource);
        self::assertStringContainsString('window.Volt.plugins = createPublicPluginsApi();', $bootSource);
        self::assertStringContainsString('window.Volt.use = function (plugin, options)', $bootSource);
    }

    public function test_runtime_source_supports_core_dom_effects_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $effectsSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '43-effects-patch.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($effectsSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());

        self::assertStringContainsString('case "text.update":', $effectsSource);
        self::assertStringContainsString('case "html.replace":', $effectsSource);
        self::assertStringContainsString('case "dom.append":', $effectsSource);
        self::assertStringContainsString('case "dom.insert":', $effectsSource);
        self::assertStringContainsString('case "dom.remove":', $effectsSource);
        self::assertStringContainsString('case "dom.move":', $effectsSource);
        self::assertStringContainsString('case "class.toggle":', $effectsSource);
        self::assertStringContainsString('case "style.set":', $effectsSource);
        self::assertStringContainsString('case "focus":', $effectsSource);
        self::assertStringContainsString('case "scroll":', $effectsSource);
        self::assertStringContainsString('case "dispatch.event":', $effectsSource);
        self::assertStringContainsString('case "navigate":', $effectsSource);

        $this->assertRuntimeAssetContains('case"text.update"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"html.replace"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"dom.append"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"dom.insert"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"dom.remove"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"dom.move"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"class.toggle"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"style.set"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"focus"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"scroll"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"dispatch.event"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"navigate"', $runtimeAsset);
    }

    public function test_runtime_source_supports_runtime_effects_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $effectsSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '43-effects-patch.js'
        );
        $runtimeAsset = $this->handleSkeletonRequest('/_volt/runtime.js');

        self::assertIsString($effectsSource);
        self::assertSame(200, $runtimeAsset->statusCode(), $runtimeAsset->content());

        self::assertStringContainsString('case "attribute.set":', $effectsSource);
        self::assertStringContainsString('case "attribute.remove":', $effectsSource);
        self::assertStringContainsString('case "blur":', $effectsSource);
        self::assertStringContainsString('case "runtime.policy":', $effectsSource);
        self::assertStringContainsString('case "state.set":', $effectsSource);
        self::assertStringContainsString('case "state.merge":', $effectsSource);
        self::assertStringContainsString('case "state.delete":', $effectsSource);
        self::assertStringContainsString('case "state.clear":', $effectsSource);

        $this->assertRuntimeAssetContains('case"attribute.set"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"attribute.remove"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"blur"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"runtime.policy"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"state.set"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"state.merge"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"state.delete"', $runtimeAsset);
        $this->assertRuntimeAssetContains('case"state.clear"', $runtimeAsset);
    }

    public function test_runtime_source_exposes_public_middleware_contract(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $middlewareSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '15-middleware-registry.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $dispatchSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );
        $patchSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '40-patch-transitions.js'
        );
        $effectsSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '43-effects-patch.js'
        );
        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );

        self::assertIsString($middlewareSource);
        self::assertIsString($visitSource);
        self::assertIsString($dispatchSource);
        self::assertIsString($patchSource);
        self::assertIsString($effectsSource);
        self::assertIsString($bootSource);
        self::assertStringContainsString('function createPublicMiddlewareApi()', $middlewareSource);
        self::assertStringContainsString('window.Volt.middleware = createPublicMiddlewareApi();', $bootSource);
        self::assertStringContainsString('runRuntimeMiddleware(', $visitSource);
        self::assertStringContainsString('runRuntimeMiddleware(', $dispatchSource);
        self::assertStringContainsString('runRuntimeMiddleware(', $patchSource);
        self::assertStringContainsString('runRuntimeMiddleware(', $effectsSource);
    }

    public function test_runtime_source_supports_action_retry_policy(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $dispatchSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );
        $requestStateSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '41-request-state.js'
        );

        self::assertIsString($dispatchSource);
        self::assertIsString($requestStateSource);
        self::assertStringContainsString('resolveRequestRetryPolicy("action"', $dispatchSource);
        self::assertStringContainsString('"volt:request-retry"', $dispatchSource);
        self::assertStringContainsString('function shouldRetryActionRequest', $requestStateSource);
    }

    public function test_runtime_source_exposes_offline_queue_and_snapshots_contracts(): void
    {
        $frameworkBasePath = self::$skeletonBasePath
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'voltstack'
            . DIRECTORY_SEPARATOR . 'framework';

        $bootstrapSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '00-bootstrap.js'
        );
        $visitSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '44-navigation-visit.js'
        );
        $actionSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '45-action-dispatch.js'
        );
        $navigationCacheSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '20-navigation-cache.js'
        );
        $bootSource = file_get_contents(
            $frameworkBasePath
            . DIRECTORY_SEPARATOR . 'frontend'
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . '50-events-and-boot.js'
        );

        self::assertIsString($bootstrapSource);
        self::assertIsString($visitSource);
        self::assertIsString($actionSource);
        self::assertIsString($navigationCacheSource);
        self::assertIsString($bootSource);
        self::assertStringContainsString('function createPublicQueueApi()', $bootstrapSource);
        self::assertStringContainsString('function createPublicSnapshotsApi()', $bootstrapSource);
        self::assertStringContainsString('function createPublicNetworkApi()', $bootstrapSource);
        self::assertStringContainsString('ensureRuntimeNetworkBindings()', $bootSource);
        self::assertStringContainsString('window.Volt.queue = createPublicQueueApi();', $bootSource);
        self::assertStringContainsString('window.Volt.snapshots = createPublicSnapshotsApi();', $bootSource);
        self::assertStringContainsString('window.Volt.network = createPublicNetworkApi();', $bootSource);
        self::assertStringContainsString('enqueueOfflineAction(', $actionSource);
        self::assertStringContainsString('"volt:navigate-offline"', $visitSource);
        self::assertStringContainsString('volt:offline:navigation:', $navigationCacheSource);
        self::assertStringContainsString('persistOfflineNavigationCacheEntry', $navigationCacheSource);
        self::assertStringContainsString('readOfflineNavigationCacheEntry', $navigationCacheSource);
        self::assertStringContainsString('offlineCachedPayload', $visitSource);
    }

    private function handleSkeletonRequest(string $path): Response
    {
        $app = new Application(self::$skeletonBasePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        foreach ((array) $app->config('app.providers', []) as $provider) {
            $app->register($provider);
        }

        $app->boot();

        $router = $app->make(Router::class);

        $routes = require self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';
        $routes($router);

        return $app->make(HttpKernel::class)->handle(Request::create($path));
    }

    private function handleSkeletonNavigationRequest(string $path): Response
    {
        $app = new Application(self::$skeletonBasePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        foreach ((array) $app->config('app.providers', []) as $provider) {
            $app->register($provider);
        }

        $app->boot();

        $router = $app->make(Router::class);

        $routes = require self::$skeletonBasePath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';
        $routes($router);

        return $app->make(HttpKernel::class)->handle(Request::create(
            $path,
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNavigationPayload(Response $response): array
    {
        $payload = $response->headers()['X-Volt-Navigation'] ?? null;

        self::assertIsString($payload);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function assertHeadInlineAssetsAreKeyed(string $html, string $route): void
    {
        $head = $this->extractHeadMarkup($html);

        self::assertSame(
            0,
            preg_match_all('/<script\b(?:(?!\bsrc=|\bdata-volt-head-key=)[^>])*>/i', $head),
            sprintf('Route %s emitted an inline <script> in <head> without src or data-volt-head-key.', $route),
        );
        self::assertSame(
            0,
            preg_match_all('/<style\b(?:(?!\bid=|\bdata-volt-head-key=)[^>])*>/i', $head),
            sprintf('Route %s emitted an inline <style> in <head> without id or data-volt-head-key.', $route),
        );
    }

    private function assertRuntimeAssetContains(string $needle, Response $runtimeAsset): void
    {
        self::assertStringContainsString(
            $this->normalizeRuntimeAssetSource($needle),
            $this->normalizeRuntimeAssetSource($runtimeAsset->content()),
        );
    }

    private function normalizeRuntimeAssetSource(string $source): string
    {
        return preg_replace('/\s+/', '', $source) ?? $source;
    }

    private function extractHeadMarkup(string $html): string
    {
        $matches = [];

        self::assertSame(
            1,
            preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $matches),
            'Expected a single <head> section in the rendered HTML.',
        );

        return $matches[1] ?? '';
    }

    private static function locateSkeletonBasePath(): string
    {
        $candidates = [
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app-skeleton',
            dirname(__DIR__, 5),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_file($candidate . DIRECTORY_SEPARATOR . 'composer.json') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'app') &&
                is_dir($candidate . DIRECTORY_SEPARATOR . 'routes')
            ) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to locate the app-skeleton base path for the integration tests.');
    }
}
