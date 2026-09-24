<?php

declare(strict_types=1);

namespace Quantum\Authorization\Context;

use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface;

final class AuthorizationContextFactory implements AuthorizationContextFactoryInterface
{
    public function __construct(
        private readonly ?AuthenticationManagerInterface $auth = null,
    ) {}

    public function create(?AuthorizationContext $context = null): AuthorizationContext
    {
        if ($context instanceof AuthorizationContext) {
            return $context;
        }

        $authContext = null;

        try {
            $authContext = $this->auth?->context();
        } catch (\Throwable) {
            $authContext = null;
        }

        if ($authContext === null) {
            return AuthorizationContext::empty();
        }

        return new AuthorizationContext(
            requestId: $authContext->requestId,
            tenantId: is_string($authContext->attribute('tenant_id')) ? $authContext->attribute('tenant_id') : null,
            channel: is_string($authContext->attribute('channel')) ? $authContext->attribute('channel') : $authContext->method,
            attributes: [
                'authentication_method' => $authContext->method,
                'authentication_assurance_profile' => $authContext->authenticationAssuranceProfile(),
                'session_public_id' => $authContext->sessionPublicId(),
                'device_reference' => $authContext->deviceReference(),
            ] + $authContext->attributes,
        );
    }
}
