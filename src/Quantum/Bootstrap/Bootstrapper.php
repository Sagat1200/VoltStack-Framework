<?php

declare(strict_types=1);

namespace Quantum\Bootstrap;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Config\BootstrapConfiguration;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use Quantum\Bootstrap\Contracts\BootstrapWarmerInterface;
use Quantum\Config\ConfigRepository;
use Quantum\Bootstrap\Graph\ProviderDependencySorter;
use Quantum\Bootstrap\Manifest\BootstrapBuildArtifact;
use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Bootstrap\Phase\BootstrapLifecycleManager;
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Bootstrap\Telemetry\BootstrapPhaseProfile;
use Quantum\Bootstrap\Telemetry\BootstrapPhaseTelemetryEmitter;
use Quantum\Compilation\BuildManifest;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class Bootstrapper implements BootstrapperInterface
{
    private ?BootstrapLifecycleManager $lifecycleManager = null;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Inyecta el BootstrapLifecycleManager que actuará como fuente de verdad
     * del estado del ciclo de vida. Se invoca automáticamente por
     * BootstrapLifecycleManager::boot() antes de delegar en bootPlan().
     */
    public function setLifecycleManager(BootstrapLifecycleManager $manager): void
    {
        $this->lifecycleManager = $manager;
    }

    /**
     * Devuelve el lifecycle manager activo, creando uno nuevo si no hay uno
     * inyectado (caso de bootPlan invocado directamente sin pasar por el manager).
     */
    private function lifecycleManager(): BootstrapLifecycleManager
    {
        return $this->lifecycleManager ??= new BootstrapLifecycleManager();
    }

    /**
     * @param array<int, class-string<ServiceProvider>|ServiceProvider> $providers
     */
    public function bootstrap(array $providers = []): Application
    {
        $this->app->registerBaseBindings();
        $this->loadConfiguration(null, false, $this->resolveFallbackMode());

        foreach ($providers as $provider) {
            $this->app->register($provider);
        }

        $this->app->boot();

        return $this->app;
    }

    public function bootstrapPlan(ApplicationPlan $plan, ?BootstrapContext $context = null): Application
    {
        return $this->bootPlan($plan, $context)->app();
    }

    public function bootPlan(ApplicationPlan $plan, ?BootstrapContext $context = null): BootstrapResult
    {
        $manager = $this->lifecycleManager();
        $phaseProfiles = [];
        $traceId = bin2hex(random_bytes(8));

        $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Discovering,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: function () use ($plan, $context): void {
                $this->app->registerBaseBindings();
                $this->app->instance(ApplicationPlan::class, $plan);
                $this->app->instance(BootstrapConfiguration::class, $plan->bootstrapConfiguration());

                if ($context !== null) {
                    $this->app->instance(BootstrapContext::class, $context);
                }

                $this->loadConfiguration(
                    $plan->configDirectory(),
                    $this->requiresPublishedConfiguration($plan),
                    $this->resolveFallbackMode($plan),
                );
            },
        );

        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $environment = $context?->environment() ?? $plan->environment();

        $orderedProviders = $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Registering,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: function () use ($plan, $context, $environment): array {
                $orderedProviders = (new ProviderDependencySorter())->sort(
                    $plan->providers(),
                    $environment,
                    $context?->profile() ?? $plan->profile(),
                );

                foreach ($orderedProviders as $provider) {
                    $this->app->register($provider);
                }

                return $orderedProviders;
            },
        );

        $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Configuring,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: function () use ($plan, $config, $environment): void {
                $config->set('bootstrap', $plan->bootstrapConfiguration()->toManifestPayload());

                if ($environment !== null) {
                    $config->set('app.env', $environment);
                }
            },
        );

        $artifact = $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Compiling,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: fn(): ?BootstrapBuildArtifact => $this->publishBootstrapArtifact($plan),
        );

        $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Booting,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: function (): void {
                $this->app->boot();
            },
        );

        $warmedBy = $this->measurePhase(
            manager: $manager,
            phase: BootstrapState::Warming,
            plan: $plan,
            context: $context,
            phaseProfiles: $phaseProfiles,
            traceId: $traceId,
            operation: fn(): array => $this->runWarmers($plan, $context),
        );

        $manager->transitionTo(BootstrapState::Ready);
        $this->recordPhaseProfile(
            phaseProfiles: $phaseProfiles,
            phase: BootstrapState::Ready,
            durationMs: 0.0,
            plan: $plan,
            context: $context,
            traceId: $traceId,
        );

        $this->emitBootstrapPhaseSummary(
            plan: $plan,
            context: $context,
            traceId: $traceId,
            phaseProfiles: $phaseProfiles,
            providerCount: count($orderedProviders),
            warmerCount: count($warmedBy),
        );

        return new BootstrapResult(
            app: $this->app,
            plan: $plan,
            context: $context,
            state: $manager->currentState(),
            warmed: true,
            ready: true,
            warmedBy: $warmedBy,
            artifact: $artifact,
            phaseProfiles: $phaseProfiles,
        );
    }

    /**
     * Carga la configuración desde el artifact publicado si existe; en caso
     * contrario aplica el modo de fallback indicado:
     *  - silent: carga desde config/*.php sin avisar (comportamiento histórico).
     *  - warn:   carga desde config/*.php emitiendo una advertencia en STDERR.
     *  - deny:   lanza PublishedConfigurationRequiredException.
     *
     * @param string $fallbackMode 'silent' | 'warn' | 'deny'
     */
    public function loadConfiguration(?string $configPath = null, bool $requirePublished = false, string $fallbackMode = 'silent'): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);

        $publishedSnapshot = $this->app->configManifestStore()->currentSnapshot();

        if ($publishedSnapshot !== null) {
            $config->loadSnapshot($publishedSnapshot);

            return;
        }

        if ($requirePublished || $fallbackMode === 'deny') {
            throw new PublishedConfigurationRequiredException(sprintf(
                'Published configuration is required for bootstrap, but no active configuration generation exists at [%s].',
                $this->app->configManifestStore()->rootPath(),
            ));
        }

        if ($fallbackMode === 'warn') {
            $this->warnFilesystemFallback($configPath);
        }

        $config->loadPath($configPath ?? $this->app->configPath());
    }

    public function loadPublishedConfiguration(?string $configPath = null): void
    {
        $this->loadConfiguration($configPath, true);
    }

    /**
     * Resuelve el modo de fallback de configuración desde el plan operacional
     * o, en su defecto, desde la variable de entorno VOLT_CONFIG_BOOT_FALLBACK.
     * Valores admitidos: 'silent' (default), 'warn', 'deny'.
     */
    private function resolveFallbackMode(?ApplicationPlan $plan = null): string
    {
        $candidates = [];

        if ($plan !== null) {
            $operational = $plan->bootstrapConfiguration()->operational();
            $candidates[] = $operational['config_boot_fallback'] ?? null;
        }

        $env = getenv('VOLT_CONFIG_BOOT_FALLBACK');
        if ($env !== false && $env !== '') {
            $candidates[] = $env;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, ['silent', 'warn', 'deny'], true)) {
                return $candidate;
            }
        }

        return 'silent';
    }

    private function warnFilesystemFallback(?string $configPath): void
    {
        $target = $configPath ?? $this->app->configPath();
        $message = sprintf(
            '[voltstack] Published configuration snapshot not found; falling back to filesystem config at [%s]. '
            . 'Set VOLT_CONFIG_BOOT_FALLBACK=deny to require published configuration, or =silent to suppress this warning.',
            $target,
        );

        if (defined('STDERR') && STDERR !== null) {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            error_log($message);
        }
    }

    public function loadRoutes(string|callable $routes): void
    {
        $router = $this->app->make(\Quantum\Routing\Router::class);

        if ($router->canServeCompiledRoutesWithoutLiveRegistration()) {
            return;
        }

        $loader = is_string($routes) ? require $routes : $routes;

        if (! is_callable($loader)) {
            throw new \RuntimeException('Bootstrapper::loadRoutes expects a route file that returns a callable or a callable loader.');
        }

        $closure = \Closure::fromCallable($loader);
        $parameters = (new \ReflectionFunction($closure))->getNumberOfParameters() === 0
            ? []
            : [$router];

        $loader(...$parameters);
    }

    private function publishBootstrapArtifact(ApplicationPlan $plan): ?BootstrapBuildArtifact
    {
        $artifactDirectory = $plan->artifactDirectory();

        if ($artifactDirectory === null || trim($artifactDirectory) === '') {
            return null;
        }

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($artifactDirectory),
            storageRoot: $artifactDirectory,
        );

        $artifact = $store->publish($plan);
        $store->activateGeneration($artifact->generationId());

        return $artifact;
    }

    private function requiresPublishedConfiguration(ApplicationPlan $plan): bool
    {
        $operational = $plan->bootstrapConfiguration()->operational();

        return ($operational['require_published_config'] ?? false) === true;
    }

    /**
     * @return list<class-string>
     */
    private function runWarmers(ApplicationPlan $plan, ?BootstrapContext $context = null): array
    {
        $operational = $plan->bootstrapConfiguration()->operational();
        $warmers = $operational['warmers'] ?? [];

        if (! is_array($warmers)) {
            return [];
        }

        $executed = [];

        foreach ($warmers as $warmerClass) {
            if (! is_string($warmerClass) || trim($warmerClass) === '') {
                continue;
            }

            /** @var mixed $warmer */
            $warmer = $this->app->make($warmerClass);

            if (! $warmer instanceof BootstrapWarmerInterface) {
                continue;
            }

            $warmer->warm($this->app, $plan, $context);
            $executed[] = $warmerClass;
        }

        return $executed;
    }

    /**
     * @param list<BootstrapPhaseProfile> $phaseProfiles
     */
    private function recordPhaseProfile(
        array &$phaseProfiles,
        BootstrapState $phase,
        float $durationMs,
        ApplicationPlan $plan,
        ?BootstrapContext $context,
        string $traceId,
        bool $successful = true,
    ): void {
        $profile = new BootstrapPhaseProfile(
            phase: $phase,
            durationMs: $durationMs,
            successful: $successful,
        );

        $phaseProfiles[] = $profile;

        if (! $this->shouldEmitPhaseTelemetry($plan)) {
            return;
        }

        $this->phaseTelemetryEmitter($traceId)?->emitPhase(
            phase: $phase,
            durationMs: $durationMs,
            plan: $plan,
            context: $context,
            successful: $successful,
        );
    }

    /**
     * @template T
     * @param list<BootstrapPhaseProfile> $phaseProfiles
     * @param callable(): T $operation
     * @return T
     */
    private function measurePhase(
        BootstrapLifecycleManager $manager,
        BootstrapState $phase,
        ApplicationPlan $plan,
        ?BootstrapContext $context,
        array &$phaseProfiles,
        string $traceId,
        callable $operation,
    ): mixed {
        $manager->transitionTo($phase);
        $startedAt = microtime(true);
        $successful = false;

        try {
            $result = $operation();
            $successful = true;

            return $result;
        } catch (Throwable $throwable) {
            throw $throwable;
        } finally {
            $this->recordPhaseProfile(
                phaseProfiles: $phaseProfiles,
                phase: $phase,
                durationMs: (microtime(true) - $startedAt) * 1000,
                plan: $plan,
                context: $context,
                traceId: $traceId,
                successful: $successful,
            );
        }
    }

    /**
     * @param list<BootstrapPhaseProfile> $phaseProfiles
     */
    private function emitBootstrapPhaseSummary(
        ApplicationPlan $plan,
        ?BootstrapContext $context,
        string $traceId,
        array $phaseProfiles,
        int $providerCount,
        int $warmerCount,
    ): void {
        if (! $this->shouldEmitPhaseTelemetry($plan)) {
            return;
        }

        $this->phaseTelemetryEmitter($traceId)?->emitSummary(
            profiles: $phaseProfiles,
            plan: $plan,
            context: $context,
            providerCount: $providerCount,
            warmerCount: $warmerCount,
        );
    }

    private function shouldEmitPhaseTelemetry(ApplicationPlan $plan): bool
    {
        $operational = $plan->bootstrapConfiguration()->operational();

        return ($operational['emit_phase_telemetry'] ?? false) === true;
    }

    private function phaseTelemetryEmitter(string $traceId): ?BootstrapPhaseTelemetryEmitter
    {
        if (! $this->app->has(TelemetryManagerInterface::class)) {
            return null;
        }

        return new BootstrapPhaseTelemetryEmitter(
            telemetry: $this->app->make(TelemetryManagerInterface::class),
            traceId: $traceId,
        );
    }
}
