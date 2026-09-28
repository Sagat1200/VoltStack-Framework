<?php

declare(strict_types=1);

namespace Quantum\Auth\Authenticators;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Decisions\AuthenticationDecision;
use Quantum\Auth\Exceptions\InvalidCredentialsException;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Auth\Tokens\InMemoryOpaqueTokenRepository;
use Quantum\Auth\Tokens\OpaqueAccessToken;

final class BearerAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
        private readonly OpaqueTokenRepositoryInterface $tokens = new InMemoryOpaqueTokenRepository(),
    ) {}

    public function supports(AuthenticationOperationContext $context): bool
    {
        if (! in_array($context->operation, ['authenticate', 'recover'], true)) {
            return false;
        }

        return $this->extractAccessTokenId($context) !== null;
    }

    public function authenticate(AuthenticationOperationContext $context): AuthenticationDecision
    {
        $tokenId = $this->extractAccessTokenId($context);

        if ($tokenId === null || trim($tokenId) === '') {
            return AuthenticationDecision::rejected([
                'reason' => 'missing_credentials',
                'authenticator' => 'bearer',
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $token = $this->tokens->findAccessToken($tokenId);

        if (! $token instanceof OpaqueAccessToken || ! $token->isActive()) {
            return AuthenticationDecision::rejected([
                'reason' => 'invalid_credentials',
                'authenticator' => 'bearer',
                'token_revoked' => $token?->revoked ?? false,
                'token_expired' => $token?->isExpired() ?? false,
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $identity = $this->identityProvider->findByIdentifier($token->reference->identifier->value);

        if ($identity === null) {
            return AuthenticationDecision::rejected([
                'reason' => 'identity_not_found',
                'authenticator' => 'bearer',
                'exception' => InvalidCredentialsException::class,
            ]);
        }

        $reference = new IdentityReference(
            identifier: $identity->identifier(),
            type: $identity->type(),
        );

        $contextAttributes = [
            'access_token_id' => $token->id->value,
            'access_token_client_id' => $token->clientId,
            'access_token_scopes' => $token->scopes,
            'access_token_issued_at' => $token->issuedAt,
            'access_token_expires_at' => $token->expiresAt,
            'refresh_token_id' => $token->refreshTokenId?->value,
        ];

        return AuthenticationDecision::authenticated(
            new AuthenticationContext(
                identity: $identity,
                reference: $reference,
                requestId: $context->request->requestId,
                method: 'bearer',
                attributes: AuthenticationAssurance::enrichAttributes($contextAttributes, 'bearer'),
            ),
            [
                'authenticator' => 'bearer',
                'identifier' => $token->reference->identifier->value,
                'access_token_scopes' => $token->scopes,
                'access_token_client_id' => $token->clientId,
            ],
        );
    }

    private function extractAccessTokenId(AuthenticationOperationContext $context): ?string
    {
        $explicit = $context->request->attribute('access_token', null);

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $header = $context->request->attribute('authorization_header', null);
        $header = is_string($header) ? trim($header) : '';

        if ($header === '' && is_array($context->request->attribute('headers', null))) {
            $headers = $context->request->attribute('headers', []);

            if (is_array($headers)) {
                foreach (['Authorization', 'authorization', 'HTTP_AUTHORIZATION'] as $key) {
                    if (isset($headers[$key]) && is_string($headers[$key]) && trim($headers[$key]) !== '') {
                        $header = trim($headers[$key]);
                        break;
                    }
                }
            }
        }

        if ($header === '') {
            return null;
        }

        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
