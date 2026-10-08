<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MultiFactorIdentityProviderInterface;
use Quantum\Auth\Credentials\PasswordCredentials;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Exceptions\InvalidSecondFactorException;
use Quantum\Auth\Exceptions\SecondFactorNotAvailableException;
use Quantum\Auth\Exceptions\SecondFactorRequiredException;
use Quantum\Auth\Exceptions\StepUpAuthenticationRequiredException;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final class TotpAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
    ) {}

    public function supports(AuthenticationOperationContext $context): bool
    {
        if ($context->operation !== 'step_up') {
            return false;
        }

        $credentials = is_array($context->request->attribute('credentials', null))
            ? $context->request->attribute('credentials', [])
            : [];
        $method = PasswordCredentials::secondFactorMethodFromArray($credentials);

        return in_array($method, ['totp', 'recovery_code'], true);
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $current = $context->currentContext;

        if ($current === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'step_up_requires_authentication',
                'authenticator' => 'totp',
                'exception' => StepUpAuthenticationRequiredException::class,
            ]);
        }

        if ($current->authenticationStrength()->value >= AuthenticationStrength::MultiFactor->value) {
            return AuthenticationDecision::authenticated($current, [
                'authenticator' => 'totp',
                'operation' => 'step_up',
                'step_up_satisfied' => true,
                'step_up_noop' => true,
            ]);
        }

        if (! $this->identityProvider instanceof MultiFactorIdentityProviderInterface) {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_not_available',
                'authenticator' => 'totp',
                'exception' => SecondFactorNotAvailableException::class,
            ]);
        }

        $available = $this->identityProvider->availableSecondFactorMethods($current->identity);
        $credentials = is_array($context->request->attribute('credentials', null))
            ? $context->request->attribute('credentials', [])
            : [];
        $method = PasswordCredentials::secondFactorMethodFromArray($credentials);
        $secondFactor = PasswordCredentials::secondFactorFromArray($credentials);

        if (! in_array($method, ['totp', 'recovery_code'], true)) {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_not_available',
                'authenticator' => 'totp',
                'exception' => SecondFactorNotAvailableException::class,
            ]);
        }

        if (! in_array($method, $available, true)) {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_not_available',
                'authenticator' => 'totp',
                'second_factor_method' => $method,
                'available_methods' => $available,
                'exception' => SecondFactorNotAvailableException::class,
            ]);
        }

        if ($secondFactor === null || trim($secondFactor) === '') {
            return AuthenticationDecision::rejected([
                'reason' => 'second_factor_required',
                'authenticator' => 'totp',
                'second_factor_method' => $method,
                'exception' => SecondFactorRequiredException::class,
            ]);
        }

        if (! $this->identityProvider->verifySecondFactor($current->identity, $secondFactor, $method)) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_second_factor',
                'authenticator' => 'totp',
                'second_factor_method' => $method,
                'exception' => InvalidSecondFactorException::class,
            ]);
        }

        $amr = is_array($current->attribute('amr')) ? $current->attribute('amr') : [];
        $extraAmr = $method === 'recovery_code'
            ? ['mfa', 'otp', 'recovery_code']
            : ['mfa', 'otp'];
        $mergedAmr = array_values(array_unique(array_filter(array_merge($amr, $extraAmr), 'is_string')));

        $attributes = array_merge($current->attributes, [
            'amr' => $mergedAmr,
            'second_factor_method' => $method,
            'assurance_value' => AuthenticationStrength::MultiFactor->value,
            'assurance_name' => 'multi_factor',
            'authentication_strength' => AuthenticationStrength::MultiFactor->name,
            'authentication_assurance_profile' => 'multi_factor',
        ]);

        return AuthenticationDecision::authenticated(
            new AuthenticationContext(
                identity: $current->identity,
                reference: $current->reference,
                requestId: $context->request->requestId,
                method: $current->method,
                attributes: AuthenticationAssurance::enrichAttributes($attributes, $current->method),
            ),
            [
                'authenticator' => 'totp',
                'operation' => 'step_up',
                'step_up_satisfied' => true,
                'second_factor_method' => $method,
            ],
        );
    }
}
