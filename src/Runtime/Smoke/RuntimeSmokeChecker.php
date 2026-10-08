<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Smoke;

use InvalidArgumentException;
use Quantum\Bootstrap\Status\BootstrapStatusInspector;
use Quantum\Http\Request;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetBaselineResolver;
use VoltStack\Runtime\Budget\RuntimeBudget;
use VoltStack\Runtime\Budget\RuntimeBudgetEvaluator;
use VoltStack\Runtime\RequestRunner;
use VoltStack\Runtime\RuntimeManager;

final class RuntimeSmokeChecker
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param list<string> $requestDefinitions
     */
    public function run(
        ?string $driver = null,
        string $profile = 'release',
        array $requestDefinitions = [],
        ?RuntimeBudget $budget = null,
        ?string $artifactDirectory = null,
        bool $useBudgetBaseline = true,
    ): RuntimeSmokeCheckReport {
        $app = $this->bootstrapCurrentApplication();
        $driver = $this->resolveDriver($app, $driver);
        $requests = $this->normalizeRequests($requestDefinitions !== []
            ? $requestDefinitions
            : $this->configuredRequests($app));
        $baseline = (new RuntimeBudgetBaselineResolver())->resolve($app, $driver);
        $effectiveBudget = $useBudgetBaseline
            ? RuntimeBudget::fromValues(
                totalMaximumMs: $budget?->totalMaximumMs() ?? $baseline->totalMaximumMs(),
                requestMaximumMs: $budget?->requestMaximumMs() ?? $baseline->requestMaximumMs(),
            )
            : RuntimeBudget::fromValues(
                totalMaximumMs: $budget?->totalMaximumMs(),
                requestMaximumMs: $budget?->requestMaximumMs(),
            );
        $inspector = new BootstrapStatusInspector($this->basePath);
        $statusBefore = $inspector->inspect($app, $artifactDirectory);

        /** @var RequestRunner $runner */
        $runner = $app->make(RequestRunner::class);
        $reports = [];

        foreach ($requests as $request) {
            $reports[] = RuntimeSmokeRequestReport::fromRunResult($runner->run($request));

            if (! $reports[array_key_last($reports)]->passed()) {
                break;
            }
        }

        $budgetReport = (new RuntimeBudgetEvaluator())->evaluate(
            $reports,
            $effectiveBudget,
        );
        $statusAfter = $inspector->inspect($app, $artifactDirectory);
        $reuse = new RuntimeBootstrapReuseReport(
            artifactDirectory: $statusAfter->artifactDirectory(),
            appInstanceId: spl_object_id($app),
            requestRunnerInstanceId: spl_object_id($runner),
            generationIdBefore: $statusBefore->generationId(),
            generationIdAfter: $statusAfter->generationId(),
            fingerprintBefore: $statusBefore->fingerprint(),
            fingerprintAfter: $statusAfter->fingerprint(),
        );

        return new RuntimeSmokeCheckReport(
            driver: $driver,
            profile: $profile,
            requests: $reports,
            budget: $budgetReport,
            budgetBaseline: $baseline,
            reuse: $reuse,
        );
    }

    private function bootstrapCurrentApplication(): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf(
                'The application bootstrap file could not be found at [%s].',
                $bootstrapPath,
            ));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        return $app;
    }

    private function resolveDriver(Application $app, ?string $driver): string
    {
        $driver = strtolower(trim($driver ?? (string) $app->config('runtime.driver', 'frankenphp')));

        /** @var RuntimeManager $manager */
        $manager = $app->make(RuntimeManager::class);
        $manager->adapter($driver);

        return $driver;
    }

    /**
     * @return list<string>
     */
    private function configuredRequests(Application $app): array
    {
        $configured = $app->config('runtime.smoke_requests', ['/']);

        if (is_string($configured) && trim($configured) !== '') {
            return [trim($configured)];
        }

        if (! is_array($configured)) {
            return ['/'];
        }

        $requests = [];

        foreach ($configured as $request) {
            if (! is_string($request) || trim($request) === '') {
                continue;
            }

            $requests[] = trim($request);
        }

        return $requests !== [] ? $requests : ['/'];
    }

    /**
     * @param list<string> $definitions
     * @return list<Request>
     */
    private function normalizeRequests(array $definitions): array
    {
        $requests = [];

        foreach ($definitions as $definition) {
            $definition = trim($definition);

            if ($definition === '') {
                continue;
            }

            $method = 'GET';
            $uri = $definition;

            if (preg_match('/^[A-Z]+:/', $definition) === 1) {
                [$method, $uri] = explode(':', $definition, 2);
                $method = strtoupper(trim($method));
                $uri = trim($uri);
            }

            if ($uri === '' || $uri[0] !== '/') {
                throw new InvalidArgumentException(sprintf(
                    'Runtime smoke request [%s] must define an absolute path like [/health] or [GET:/health].',
                    $definition,
                ));
            }

            $requests[] = Request::create($uri, $method);
        }

        if ($requests === []) {
            throw new InvalidArgumentException('Runtime smoke-check requires at least one request definition.');
        }

        return $requests;
    }
}
