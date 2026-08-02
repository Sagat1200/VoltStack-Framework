<?php

declare(strict_types=1);

namespace VoltStack\Framework\Exceptions;

use Quantum\Http\JsonResponse;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Controllers\Exceptions\ControllerException;
use Quantum\Routing\Exceptions\MethodNotAllowedException;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use Quantum\Routing\Exceptions\RouteNotFoundException;
use Quantum\Security\Exceptions\CsrfTokenMismatchException;
use Quantum\Security\Exceptions\InvalidSignatureException;
use Quantum\Validation\Exceptions\ValidationException;
use Throwable;
use VoltStack\Framework\Contracts\ExceptionHandler as ExceptionHandlerContract;
use VoltStack\Runtime\Component\Exceptions\ComponentMountException;
use VoltStack\Runtime\Component\Exceptions\ComponentRenderException;
use VoltStack\Runtime\Component\Exceptions\InvalidComponentActionException;
use VoltStack\Runtime\Hydration\Exceptions\InvalidSnapshotException;

final class ExceptionHandler implements ExceptionHandlerContract
{
    public function render(Request $request, Throwable $exception): Response
    {
        $status = $this->statusCode($exception);
        $headers = $this->responseHeaders($exception);

        if ($request->isVoltActionRequest()) {
            return $this->voltErrorResponse($exception, $status, $headers);
        }

        if ($request->expectsJson()) {
            return $this->jsonResponse($exception, $status, $headers);
        }

        return new Response($this->htmlResponse($exception, $status), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            ...$headers,
        ]);
    }

    private function statusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof RouteNotFoundException => 404,
            $exception instanceof MissingRouteBindingException => 404,
            $exception instanceof MethodNotAllowedException => 405,
            $exception instanceof InvalidSignatureException => 403,
            $exception instanceof CsrfTokenMismatchException => 419,
            $exception instanceof InvalidSnapshotException => 422,
            $exception instanceof ValidationException => 422,
            $exception instanceof InvalidComponentActionException => 422,
            $exception instanceof ControllerException => 500,
            $exception instanceof ComponentMountException => 500,
            $exception instanceof ComponentRenderException => 500,
            default => 500,
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function jsonResponse(Throwable $exception, int $status, array $headers): JsonResponse
    {
        $payload = [
            'message' => $this->jsonMessage($exception, $status),
        ];

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function voltErrorResponse(Throwable $exception, int $status, array $headers): JsonResponse
    {
        $payload = [
            'error' => $this->voltErrorPayload($exception, $status),
        ];

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function voltErrorPayload(Throwable $exception, int $status): array
    {
        $payload = [
            'type' => $exception::class,
            'kind' => 'protocol-error',
            'code' => $this->errorCode($exception, $status),
            'status' => $status,
            'message' => $this->jsonMessage($exception, $status),
        ];

        if ($exception instanceof MethodNotAllowedException) {
            $payload['allow'] = $exception->allowedMethods();
            $payload['allowHeader'] = $exception->allowHeader();
        }

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        return $payload;
    }

    private function htmlResponse(Throwable $exception, int $status): string
    {
        $title = match ($status) {
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Validation Failed',
            default => 'Server Error',
        };

        $body = $exception instanceof ValidationException
            ? $this->renderValidationErrors($exception)
            : '<p>' . match ($status) {
                403 => 'Invalid signature.',
                404 => 'The requested page could not be found.',
                405 => 'The requested HTTP method is not allowed for this route.',
                419 => 'CSRF token mismatch.',
                default => 'An unexpected error occurred while processing the request.',
            } . '</p>';

        return sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="volt-document" content="reload" data-volt-head-key="error-document-reload"><meta name="volt-navigation-mode" content="reload" data-volt-head-key="error-navigation-mode-reload"><title>%1$s</title><style>body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}main{max-width:720px;margin:0 auto;background:#111827;border:1px solid #334155;border-radius:12px;padding:32px;}h1{margin-top:0;}ul{padding-left:20px;}code{background:#1e293b;padding:2px 6px;border-radius:4px;}</style></head><body data-volt-document="reload"><main><h1>%1$s</h1>%2$s</main></body></html>',
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body,
        );
    }

    private function renderValidationErrors(ValidationException $exception): string
    {
        $items = [];

        foreach ($exception->errors() as $field => $messages) {
            $items[] = sprintf(
                '<li><strong>%s</strong>: %s</li>',
                htmlspecialchars($field, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars(implode(' ', $messages), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return '<p>The submitted data did not pass validation.</p><ul>' . implode('', $items) . '</ul>';
    }

    private function jsonMessage(Throwable $exception, int $status): string
    {
        return match (true) {
            $exception instanceof ValidationException => $exception->getMessage(),
            $exception instanceof CsrfTokenMismatchException => $exception->getMessage(),
            $exception instanceof InvalidSignatureException => $exception->getMessage(),
            $exception instanceof InvalidSnapshotException => $exception->getMessage(),
            $exception instanceof InvalidComponentActionException => $exception->getMessage(),
            $exception instanceof ComponentMountException => 'Server Error',
            $exception instanceof ComponentRenderException => 'Server Error',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status === 405 => 'Method Not Allowed',
            default => 'Server Error',
        };
    }

    private function errorCode(Throwable $exception, int $status): string
    {
        return match (true) {
            $exception instanceof RouteNotFoundException => 'route.not_found',
            $exception instanceof MissingRouteBindingException => 'route.binding_missing',
            $exception instanceof MethodNotAllowedException => 'route.method_not_allowed',
            $exception instanceof InvalidSignatureException => 'security.invalid_signature',
            $exception instanceof CsrfTokenMismatchException => 'security.csrf_token_mismatch',
            $exception instanceof InvalidSnapshotException => 'runtime.invalid_snapshot',
            $exception instanceof ValidationException => 'runtime.validation_failed',
            $exception instanceof InvalidComponentActionException => 'runtime.action_not_allowed',
            $exception instanceof ControllerException => $exception->errorCode(),
            $exception instanceof ComponentMountException => 'runtime.component_mount_failed',
            $exception instanceof ComponentRenderException => 'runtime.component_render_failed',
            $status === 500 => 'server.error',
            default => 'runtime.error',
        };
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(Throwable $exception): array
    {
        $headers = [];
        $errorCode = $this->errorCode($exception, $this->statusCode($exception));

        if ($this->shouldExposeVoltErrorCode($errorCode)) {
            $headers['X-Volt-Error-Code'] = $errorCode;
        }

        if ($exception instanceof MethodNotAllowedException) {
            return [
                ...$headers,
                'Allow' => $exception->allowHeader(),
            ];
        }

        return $headers;
    }

    private function shouldExposeVoltErrorCode(string $errorCode): bool
    {
        return $errorCode !== 'server.error' && $errorCode !== 'runtime.error';
    }
}
