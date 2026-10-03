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
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Bootstrap\Phase\BootstrapStateMachine;
use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class Bootstrapper implements BootstrapperInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param array<int, class-string<ServiceProvider>|ServiceProvider> $providers
     */
    public function bootstrap(array $providers = []): Application
    {
        $this->app->registerBaseBindings();
        $this->loadConfiguration();

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
        $stateMachine = new BootstrapStateMachine();
        $stateMachine->transitionTo(BootstrapState::Discovering);
        $this->app->registerBaseBindings();
        $this->app->instance(ApplicationPlan::class, $plan);
        $this->app->instance(BootstrapConfiguration::class, $plan->bootstrapConfiguration());

        if ($context !== null) {
            $this->app->instance(BootstrapContext::class, $context);
        }

        $this->loadConfiguration($plan->configDirectory());

        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('bootstrap', $plan->bootstrapConfiguration()->toManifestPayload());

        $environment = $context?->environment() ?? $plan->environment();
        if ($environment !== null) {
            $config->set('app.env', $environment);
        }

        $stateMachine->transitionTo(BootstrapState::Registering);
        $orderedProviders = (new ProviderDependencySorter())->sort(
            $plan->providers(),
            $environment,
            $context?->profile() ?? $plan->profile(),
        );

        foreach ($orderedProviders as $provider) {
            $this->app->register($provider);
        }

        $stateMachine->transitionTo(BootstrapState::Configuring);
        $stateMachine->transitionTo(BootstrapState::Compiling);
        $artifact = $this->publishBootstrapArtifact($plan);

        $stateMachine->transitionTo(BootstrapState::Booting);
        $this->app->boot();

        $stateMachine->transitionTo(BootstrapState::Warming);
        $warmedBy = $this->runWarmers($plan, $context);

        $stateMachine->transitionTo(BootstrapState::Ready);

        return new BootstrapResult(
            app: $this->app,
            plan: $plan,
            context: $context,
            state: $stateMachine->current(),
            warmed: true,
            ready: true,
            warmedBy: $warmedBy,
            artifact: $artifact,
        );
    }

    public function loadConfiguration(?string $configPath = null): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->loadPath($configPath ?? $this->app->configPath());
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
}
