<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Auth\Exceptions\GuestOnlyException;
use Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException;
use Quantum\Controllers\Security\Exceptions\AuthorizationDeniedException;
use Quantum\Controllers\Security\Exceptions\ControllerExposureViolationException;
use Quantum\Controllers\Security\Exceptions\ControllerSecurityExceptionMapper;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;
use Quantum\Controllers\Security\Exceptions\TenantViolationException;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Exceptions\Enums\ExceptionOrigin;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Exceptions\ExceptionHandler;
use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\Runtime\ExceptionHandlingState;
use Quantum\Exceptions\Runtime\RuntimeContext;
use Quantum\Http\Request;
use Quantum\Metadata\MetadataBag;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportExecution;

final class QuantumExceptionHandlerTest extends TestCase
{
    public function test_it_aborts_when_transport_emission_has_started(): void
    {
        $handler = new ExceptionHandler();
        $throwable = new Exception('boom');

        $transportExecution = new TransportExecution(
            response: new TransportResponse(),
            context: new TransportContext(),
        );
        $transportExecution->emissionStarted = true;

        $context = new ExceptionHandlingContext(
            throwable: $throwable,
            origin: ExceptionOrigin::TransportEmission,
            runtime: new RuntimeContext(environment: 'local'),
            request: null,
            controllerExecution: null,
            transportExecution: $transportExecution,
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: true,
        );

        $result = $handler->handle($throwable, $context);

        self::assertNull($result->response);
        self::assertSame(WorkerDisposition::Terminate, $result->workerDisposition);
        self::assertTrue($result->emissionStarted);
    }

    public function test_security_mapper_authentication_required_401_with_www_authenticate_and_body(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper());

        $ex = new AuthenticationRequiredException(
            reasonCode: 'authentication_strength_insufficient',
            challengeMetadata: [
                'required_strength_value' => AuthenticationStrength::MultiFactor->value,
                'required_strength_name' => 'MultiFactor',
                'current_strength_value' => AuthenticationStrength::Password->value,
            ],
            safeContext: [
                'policy_id' => 'auth_strength_policy',
                'reason_code' => 'authentication_strength_insufficient',
                'required_strength_value' => AuthenticationStrength::MultiFactor->value,
                'current_strength_value' => AuthenticationStrength::Password->value,
            ],
            message: 'MFA required',
        );

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: false,
        );
        $result = $handler->handle($ex, $context);

        self::assertSame(401, $result->response->statusCode());
        $wwwAuth = $result->response->headers()['WWW-Authenticate'] ?? null;
        self::assertNotNull($wwwAuth);
        self::assertStringContainsString('Bearer', $wwwAuth);
        self::assertStringContainsString('required_strength_value="30"', $wwwAuth);
        self::assertStringContainsString('current_strength_value="10"', $wwwAuth);
        self::assertStringContainsString('error="insufficient_strength"', $wwwAuth);

        $payload = json_decode($result->response->content(), true);
        self::assertIsArray($payload);
        self::assertSame('MFA required', $payload['message'] ?? null);
        self::assertSame('authentication_strength_insufficient', $payload['reason_code'] ?? null);
        self::assertIsArray($payload['challenge'] ?? null);
        self::assertSame(30, $payload['challenge']['required_strength_value'] ?? null);
    }

    public function test_security_mapper_authorization_denied_403_safe_context_hidden_when_not_debug(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper([
            'expose_safe_context' => false,
        ]));

        $ex = new AuthorizationDeniedException(
            reasonCode: 'policy_requires_admin_role',
            safeContext: [
                'policy_id' => 'role.admin',
                'current_roles' => ['user'],
                'resource_owner' => 'user:1234',
            ],
            message: 'Admin role required',
        );

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: false,
        );
        $result = $handler->handle($ex, $context);

        self::assertSame(403, $result->response->statusCode());
        $payload = json_decode($result->response->content(), true);
        self::assertSame('Admin role required', $payload['message'] ?? null);
        self::assertSame('policy_requires_admin_role', $payload['reason_code'] ?? null);
        self::assertArrayNotHasKey('safe_context', $payload);
    }

    public function test_security_mapper_authorization_denied_403_safe_context_exposed_when_debug_true(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper([
            'expose_safe_context' => false,
        ]));

        $ex = new AuthorizationDeniedException(
            reasonCode: 'missing_permission',
            safeContext: [
                'policy_id' => 'scope.read',
                'missing_permissions' => ['documents:read'],
            ],
        );

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: true,
        );
        $result = $handler->handle($ex, $context);
        $payload = json_decode($result->response->content(), true);

        self::assertSame(403, $result->response->statusCode());
        self::assertIsArray($payload['safe_context'] ?? null);
        self::assertSame(['documents:read'], $payload['safe_context']['missing_permissions'] ?? null);
    }

    public function test_security_mapper_tenant_violation_returns_404_to_avoid_leaking_tenant_info(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper());

        $ex = new TenantViolationException('Cross-tenant access forbidden');

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: false,
        );
        $result = $handler->handle($ex, $context);

        self::assertSame(404, $result->response->statusCode());
        self::assertSame('1', $result->response->headers()['X-Volt-Tenant-Hidden'] ?? null);
        $payload = json_decode($result->response->content(), true);
        self::assertSame('Not Found', $payload['message'] ?? null);
    }

    public function test_security_mapper_exposure_violation_default_451_and_overridable_403_via_config(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper());

        $ex = new ControllerExposureViolationException(
            reasonCode: 'metadata_explicit_unexposed',
            targetSignature: 'Acme\\SecretController::operation',
            safeContext: [
                'target_signature' => 'Acme\\SecretController::operation',
                'exposure_source' => 'php_attribute_expose_false',
            ],
            message: 'Explicit unexposed',
        );

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: false,
        );
        $result = $handler->handle($ex, $context);
        self::assertSame(451, $result->response->statusCode());
        $payloadDefault = json_decode($result->response->content(), true);
        self::assertSame('metadata_explicit_unexposed', $payloadDefault['reason_code'] ?? null);

        $handler2 = new ExceptionHandler();
        $handler2->addMapper(new ControllerSecurityExceptionMapper([
            'http_codes' => ['exposure_violation' => 403],
        ]));
        $result2 = $handler2->handle($ex, $context);
        self::assertSame(403, $result2->response->statusCode());
    }

    public function test_security_mapper_infrastructure_failure_500_never_exposes_safe_context_even_debug(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper([
            'expose_safe_context' => true,
        ]));

        $ex = new SecurityInfrastructureFailureException('Policy resolution failed: unknown dependency');

        $request = Request::create(
            '/t',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: true,
        );
        $result = $handler->handle($ex, $context);
        $payload = json_decode($result->response->content(), true);

        self::assertSame(500, $result->response->statusCode());
        self::assertSame('Internal Server Error', $payload['message'] ?? null);
        self::assertArrayNotHasKey('safe_context', $payload);
    }

    public function test_auth_mapper_guest_only_returns_403_without_www_authenticate(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new AuthExceptionMapper());

        $ex = new GuestOnlyException();
        $request = Request::create(
            '/guest-only',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
            ],
        );
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: false,
        );

        $result = $handler->handle($ex, $context);
        $payload = json_decode($result->response->content(), true);

        self::assertSame(403, $result->response->statusCode());
        self::assertSame('Only guests may access this resource.', $payload['message'] ?? null);
        self::assertSame('auth.guest_only', $payload['reason_code'] ?? null);
        self::assertSame('auth.guest_only', $result->response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertArrayNotHasKey('WWW-Authenticate', $result->response->headers());
    }

    public function test_security_mapper_html_response_includes_reason_code_and_debug_safe_context(): void
    {
        $handler = new ExceptionHandler();
        $handler->addMapper(new ControllerSecurityExceptionMapper([
            'expose_safe_context' => true,
        ]));

        $ex = new AuthorizationDeniedException(
            reasonCode: 'role_missing',
            safeContext: ['missing_role' => 'billing'],
        );
        $request = Request::create('/t', 'GET');
        $context = new ExceptionHandlingContext(
            throwable: $ex,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: 'local'),
            request: $request,
            controllerExecution: null,
            transportExecution: new TransportExecution(response: new TransportResponse(), context: new TransportContext()),
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: true,
        );
        $result = $handler->handle($ex, $context);

        self::assertSame(403, $result->response->statusCode());
        self::assertStringContainsString('You do not have permission', $result->response->content());
        self::assertStringContainsString('reason_code: role_missing', $result->response->content());
        self::assertStringContainsString('missing_role', $result->response->content());
    }
}
