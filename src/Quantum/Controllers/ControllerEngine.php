<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Dispatching\MissingRouteHandler;
use Quantum\Routing\Dispatching\ResponseNormalizer;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class ControllerEngine
{
    public function __construct(
        private readonly Application $app,
        private readonly ControllerResolver $resolver,
        private readonly ParameterResolutionEngine $parameters,
        private readonly MissingRouteHandler $missing,
        private readonly ControllerInvoker $invoker,
        private readonly ResponseNormalizer $normalizer,
    ) {}

    public function handle(RouteMatch $match, Request $request): Response
    {
        $definition = new ControllerDefinition($match->route()->action());
        $context = new ControllerContext($this->app, $match, $request);
        $resolved = $this->resolver->resolve($definition, $context);

        try {
            $arguments = $this->parameters->resolve($resolved, $context);
        } catch (MissingRouteBindingException $exception) {
            return $this->normalizer->normalize($this->missing->handle($match, $request, $exception));
        }

        $executionContext = new ControllerExecutionContext($request, $match);
        $result = $this->invoker->invoke($resolved, $arguments, $executionContext);

        return $this->normalizer->normalize($result);
    }
}
