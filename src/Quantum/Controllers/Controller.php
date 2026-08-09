<?php

declare(strict_types=1);

namespace Quantum\Controllers;

use Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Http\JsonResponse;
use Quantum\Http\RedirectResponse;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\RouteMatch;
use Quantum\Validation\Validator;
use Quantum\View\View;

abstract class Controller implements ControllerExecutionContextAwareInterface
{
    private ?ControllerExecutionContext $__context = null;

    public function setControllerExecutionContext(ControllerExecutionContext $context): void
    {
        $this->__context = $context;
    }

    public function releaseControllerExecutionContext(): void
    {
        $this->__context = null;
    }

    protected function request(): Request
    {
        if ($this->__context === null) {
            throw new \RuntimeException(sprintf(
                'Controller %s is not running inside a ControllerEngine invocation — request() is unavailable.',
                static::class,
            ));
        }

        return $this->__context->request();
    }

    protected function route(): RouteMatch
    {
        if ($this->__context === null) {
            throw new \RuntimeException(sprintf(
                'Controller %s is not running inside a ControllerEngine invocation — route() is unavailable.',
                static::class,
            ));
        }

        return $this->__context->match();
    }

    protected function security(): ?ControllerSecurityContext
    {
        return $this->__context?->securityContext();
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function view(string $name, array $data = []): View
    {
        return view($name, $data);
    }

    protected function response(string $content = '', int $statusCode = 200, array $headers = []): Response
    {
        return response($content, $statusCode, $headers);
    }

    protected function json(mixed $data, int $statusCode = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $statusCode, $headers);
    }

    protected function redirect(string $location, int $statusCode = 302, array $headers = []): RedirectResponse
    {
        return response()->redirect($location, $statusCode, $headers);
    }

    protected function validate(array $data, array $rules): array
    {
        return app(Validator::class)->validate($data, $rules);
    }
}
