<?php

declare(strict_types=1);

namespace Quantum\Exceptions;

use Quantum\Controllers\Exceptions\ControllerException;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface;
use Quantum\Exceptions\Contracts\ExceptionMapperInterface;
use Quantum\Exceptions\Enums\ExceptionHandlingStatus;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\JsonResponse;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Routing\Exceptions\MethodNotAllowedException;
use Quantum\Routing\Exceptions\MissingRouteBindingException;
use Quantum\Routing\Exceptions\RouteNotFoundException;
use Quantum\Security\Exceptions\CsrfTokenMismatchException;
use Quantum\Security\Exceptions\InvalidSignatureException;
use Quantum\Validation\Exceptions\ValidationException;
use Throwable;
use VoltStack\Runtime\Component\Exceptions\ComponentMountException;
use VoltStack\Runtime\Component\Exceptions\ComponentRenderException;
use VoltStack\Runtime\Component\Exceptions\InvalidComponentActionException;
use VoltStack\Runtime\Hydration\Exceptions\InvalidSnapshotException;

final class ExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @var list<ExceptionMapperInterface>
     */
    private array $mappers = [];

    public function addMapper(ExceptionMapperInterface $mapper): void
    {
        $this->mappers[] = $mapper;
    }

    public function handle(Throwable $throwable, ExceptionHandlingContext $context): ExceptionHandlingResult
    {
        $context->state->attempts++;
        $context->state->status = ExceptionHandlingStatus::Mapping;

        $emissionStarted = $context->transportExecution?->emissionStarted ?? false;

        if ($emissionStarted) {
            $context->state->status = ExceptionHandlingStatus::Aborted;

            return new ExceptionHandlingResult(
                response: null,
                workerDisposition: WorkerDisposition::Terminate,
                emissionStarted: true,
            );
        }

        $request = $context->request;
        $status = $this->statusCode($throwable);
        $headers = $this->responseHeaders($throwable);

        $context->state->status = ExceptionHandlingStatus::Rendering;

        $response = $this->renderResponse($request, $throwable, $status, $headers, $context->debug);

        $context->state->status = ExceptionHandlingStatus::Handled;

        return new ExceptionHandlingResult(
            response: $response,
            workerDisposition: WorkerDisposition::Reuse,
            emissionStarted: $emissionStarted,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function renderResponse(?Request $request, Throwable $exception, int $status, array $headers, bool $debug): Response
    {
        if ($request?->isVoltActionRequest() === true) {
            return $this->voltErrorResponse($exception, $status, $headers, $debug);
        }

        if ($request?->expectsJson() === true) {
            return $this->jsonResponse($exception, $status, $headers, $debug);
        }

        return new Response($this->htmlResponse($exception, $status, $debug), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            ...$headers,
        ]);
    }

    private function statusCode(Throwable $exception): int
    {
        foreach ($this->mappers as $mapper) {
            if (null !== $code = $mapper->statusCode($exception)) {
                return $code;
            }
        }

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
    private function jsonResponse(Throwable $exception, int $status, array $headers, bool $debug): JsonResponse
    {
        $payload = [
            'message' => $this->jsonMessage($exception, $status),
        ];

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        $extensions = [];
        foreach ($this->mappers as $mapper) {
            $extensions[] = $mapper->jsonExtensions($exception, $debug);
        }
        if ($extensions !== []) {
            $merged = array_merge(...$extensions);
            if ($merged !== []) {
                $payload = array_merge($payload, $merged);
            }
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function voltErrorResponse(Throwable $exception, int $status, array $headers, bool $debug): JsonResponse
    {
        $payload = [
            'error' => $this->voltErrorPayload($exception, $status, $debug),
        ];

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function voltErrorPayload(Throwable $exception, int $status, bool $debug): array
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

        $extensions = [];
        foreach ($this->mappers as $mapper) {
            $extensions[] = $mapper->voltExtensions($exception, $debug);
        }
        if ($extensions !== []) {
            $merged = array_merge(...$extensions);
            if ($merged !== []) {
                $payload = array_merge($payload, $merged);
            }
        }

        return $payload;
    }

    private function htmlResponse(Throwable $exception, int $status, bool $debug): string
    {
        $title = match ($status) {
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Validation Failed',
            451 => 'Unavailable For Legal Reasons',
            default => 'Server Error',
        };

        $customBody = null;
        foreach ($this->mappers as $mapper) {
            if (null !== $b = $mapper->htmlBody($exception, $status)) {
                $customBody = $b;
                break;
            }
        }

        $body = match (true) {
            $customBody !== null => $customBody,
            $exception instanceof ValidationException => $this->renderValidationErrors($exception),
            $exception instanceof InvalidSignatureException => '<p>Invalid signature.</p>',
            $exception instanceof CsrfTokenMismatchException => '<p>CSRF token mismatch.</p>',
            $exception instanceof MethodNotAllowedException => '<p>The requested HTTP method is not allowed for this route.</p>',
            default => '<p>' . match ($status) {
                401 => 'Authentication is required to access this resource.',
                403 => 'You do not have permission to access this resource.',
                404 => 'The requested page could not be found.',
                419 => 'CSRF token mismatch.',
                422 => 'The submitted data did not pass validation.',
                451 => 'Access to this resource is restricted by policy.',
                default => 'An unexpected error occurred while processing the request.',
            } . '</p>',
        };

        if ($debug) {
            $escMsg = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escFile = htmlspecialchars($exception->getFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escLine = (int)$exception->getLine();
            $escTrace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body .= '<div style="margin-top:20px; padding:14px; background:#0b1220; border:1px solid #334155; border-radius:8px;">'
                . '<p style="margin:0 0 8px 0;"><strong style="color:#fca5a5;">EXCEPTION DEBUG:</strong> <code style="color:#f87171;">' . htmlspecialchars($exception::class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>'
                . '<p style="margin:0 0 8px 0;"><strong>Message:</strong> <code>' . $escMsg . '</code></p>'
                . '<p style="margin:0 0 8px 0;"><strong>Location:</strong> <code>' . $escFile . ':' . $escLine . '</code></p>'
                . '<details open><summary style="cursor:pointer;opacity:0.85;">Stack trace</summary><pre style="margin-top:8px;padding:10px;background:#020617;border:1px solid #1e293b;border-radius:6px;white-space:pre-wrap;font-size:12px;">' . $escTrace . '</pre></details>'
                . '</div>';
        }

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
        foreach ($this->mappers as $mapper) {
            if (null !== $msg = $mapper->message($exception, $status)) {
                return $msg;
            }
        }

        return match (true) {
            $exception instanceof ValidationException => $exception->getMessage(),
            $exception instanceof CsrfTokenMismatchException => $exception->getMessage(),
            $exception instanceof InvalidSignatureException => $exception->getMessage(),
            $exception instanceof InvalidSnapshotException => $exception->getMessage(),
            $exception instanceof InvalidComponentActionException => $exception->getMessage(),
            $exception instanceof ComponentMountException => 'Server Error',
            $exception instanceof ComponentRenderException => 'Server Error',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status === 405 => 'Method Not Allowed',
            $status === 451 => 'Unavailable For Legal Reasons',
            default => 'Server Error',
        };
    }

    private function errorCode(Throwable $exception, int $status): string
    {
        foreach ($this->mappers as $mapper) {
            if (null !== $code = $mapper->errorCode($exception, $status)) {
                return $code;
            }
        }

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

            // Errores internos de PHP (Bloque 20 Stabilizer) — reason codes RFC 9457 específicos
            $exception instanceof \DivisionByZeroError => 'runtime.math.division_by_zero',
            $exception instanceof \UnhandledMatchError => 'runtime.match.unhandled_case',
            $exception instanceof \ArgumentCountError => 'runtime.argument_count_mismatch',
            $exception instanceof \ValueError => 'runtime.value_error',
            $exception instanceof \TypeError => 'runtime.type_error',
            $exception instanceof \ArithmeticError => 'runtime.math.arithmetic_error',
            $exception instanceof \ParseError => 'runtime.parse_error',
            $exception instanceof \CompileError => 'runtime.compile_error',
            $exception instanceof \FatalError => 'runtime.fatal_error',

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

        foreach ($this->mappers as $mapper) {
            $mh = $mapper->headers($exception);
            if ($mh !== []) {
                $headers = array_merge($headers, $mh);
            }
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
