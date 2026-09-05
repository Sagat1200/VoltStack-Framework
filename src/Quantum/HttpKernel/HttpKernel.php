<?php

declare(strict_types=1);

namespace Quantum\HttpKernel;

use JsonException;
use Quantum\Auth\Runtime\AuthenticationResponseDecorator;
use Quantum\Http\JsonResponse;
use Quantum\Http\HtmlDocumentBootstrapper;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\HttpKernel\CompiledMiddlewarePipeline;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\HttpKernel\MiddlewareStack;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;
use Quantum\Routing\Router;
use Quantum\Routing\Dispatching\ResponseNormalizer;
use Quantum\Routing\SpaNavigationPayloadFactory;
use RuntimeException;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\ExceptionHandler as ExceptionHandlerContract;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\ScopeManager;

class HttpKernel implements KernelContract
{
    /**
     * @var array<int, class-string<MiddlewareInterface>|callable|MiddlewareInterface>
     */
    protected array $middlewares = [];
    protected CompiledMiddlewarePipeline $compiledMiddlewarePipeline;

    public function __construct(
        protected Application $app,
        protected Router $router,
        protected ResponseNormalizer $normalizer,
        ?array $middlewares = null,
    ) {
        $this->compiledMiddlewarePipeline = CompiledMiddlewarePipeline::compile([]);

        if ($middlewares !== null) {
            $this->setMiddlewares($middlewares);
        }
    }

    /**
     * @param array<int, class-string<MiddlewareInterface>|callable|MiddlewareInterface> $middlewares
     */
    public function setMiddlewares(array $middlewares): void
    {
        $this->middlewares = MiddlewareStack::deduplicate($this->middlewareAliases()->resolveMany($middlewares));
        $this->compiledMiddlewarePipeline = CompiledMiddlewarePipeline::compile($this->middlewares);
    }

    public function pushMiddleware(callable|string|MiddlewareInterface $middleware): void
    {
        $this->middlewares = MiddlewareStack::deduplicate([
            ...$this->middlewares,
            $this->middlewareAliases()->resolve($middleware),
        ]);
        $this->compiledMiddlewarePipeline = CompiledMiddlewarePipeline::compile($this->middlewares);
    }

    public function aliasMiddleware(string $alias, mixed $middleware): void
    {
        $this->middlewareAliases()->alias($alias, $middleware);
    }

    public function handle(Request $request): Response
    {
        $this->app->boot();
        $scope = $this->app->make(ScopeManager::class);
        $scope->begin($request);
        $response = null;

        try {
            try {
                $response = $this->compiledMiddlewarePipeline->handle(
                    $this->app,
                    $request,
                    fn(Request $request): mixed => $this->router->dispatch($request),
                );

                $response = $this->normalizer->normalize($response);
            } catch (Throwable $exception) {
                $response = $this->app->make(ExceptionHandlerContract::class)->render($request, $exception);
            }

            $response = $this->app->make(AuthenticationResponseDecorator::class)->decorate($request, $response);
        } finally {
            $scope->end();
        }

        $response = $this->bootstrapHtmlResponse($request, $response);
        $response = $this->decorateVoltNavigationResponse($request, $response);

        if ($request->method() === 'HEAD') {
            $response->setContent('');
        }

        return $response;
    }

    private function bootstrapHtmlResponse(Request $request, Response $response): Response
    {
        $bootstrapper = $this->app->make(HtmlDocumentBootstrapper::class);

        if (! $bootstrapper->shouldBootstrap($request, $response)) {
            return $response;
        }

        return $bootstrapper->bootstrap($request, $response);
    }

    private function decorateVoltNavigationResponse(Request $request, Response $response): Response
    {
        if (! $request->isVoltRequest() || ! $request->isVoltNavigation() || $request->isInternalEndpoint()) {
            return $response;
        }

        $factory = $this->app->make(SpaNavigationPayloadFactory::class);

        try {
            $payload = json_encode(
                $factory->fromRequestAndResponse($request, $response)->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the Volt navigation payload.', 0, $exception);
        }

        return $response->header('X-Volt-Navigation', $payload);
    }

    public function compiledMiddlewarePipeline(): CompiledMiddlewarePipeline
    {
        return $this->compiledMiddlewarePipeline;
    }

    private function middlewareAliases(): MiddlewareAliasRegistry
    {
        return $this->app->make(MiddlewareAliasRegistry::class);
    }
}
