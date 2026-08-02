<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\Exceptions\ControllerParameterResolutionException;
use Quantum\Routing\Dispatching\RouteArgumentResolver;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use RuntimeException;

final class ParameterResolutionEngine
{
    public function __construct(private readonly RouteArgumentResolver $arguments) {}

    public function resolve(ResolvedController $controller, ControllerContext $context): array
    {
        $match = $context->match();
        $parameterAliases = $match->route()->routeMetadata()->get('parameter_aliases', []);

        try {
            return $this->arguments->forMethod(
                $controller->instance(),
                $controller->method(),
                $context->request(),
                $match->parameters(),
                $match->route()->uri(),
                is_array($parameterAliases) ? $parameterAliases : [],
            );
        } catch (MissingRouteBindingException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new ControllerParameterResolutionException($exception);
        }
    }
}
