<?php

declare(strict_types=1);

namespace Quantum\Authorization\Principal;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Contracts\PrincipalResolverInterface;

final class PrincipalResolver implements PrincipalResolverInterface
{
    public function __construct(
        private readonly ?AuthenticationManagerInterface $auth = null,
    ) {}

    public function resolve(mixed $principal = null): PrincipalInterface
    {
        if ($principal instanceof PrincipalInterface) {
            return $principal;
        }

        if ($principal instanceof AuthenticationContext) {
            return $this->fromAuthenticationContext($principal);
        }

        if ($principal instanceof IdentityInterface) {
            return new Principal(
                $principal->identifier()->value,
                $this->principalType($principal->type()),
                true,
                ['identity_type' => $principal->type()],
            );
        }

        if (is_array($principal)) {
            return $this->fromArray($principal);
        }

        if (is_object($principal)) {
            return $this->fromObject($principal);
        }

        if (is_string($principal) || is_int($principal)) {
            return new Principal((string) $principal);
        }

        $authContext = null;

        try {
            $authContext = $this->auth?->context();
        } catch (\Throwable) {
            $authContext = null;
        }

        if ($authContext instanceof AuthenticationContext) {
            return $this->fromAuthenticationContext($authContext);
        }

        $user = null;

        try {
            $user = $this->auth?->user();
        } catch (\Throwable) {
            $user = null;
        }

        if ($user !== null) {
            return is_object($user)
                ? $this->fromObject($user)
                : (is_array($user) ? $this->fromArray($user) : new Principal((string) $user));
        }

        return new AnonymousPrincipal();
    }

    private function fromAuthenticationContext(AuthenticationContext $context): PrincipalInterface
    {
        return new Principal(
            $context->identity->identifier()->value,
            $this->principalType($context->identity->type()),
            true,
            [
                'identity_type' => $context->identity->type(),
                'authentication_method' => $context->method,
                'authentication_assurance_profile' => $context->authenticationAssuranceProfile(),
                'authentication_strength' => $context->authenticationStrength()->value,
                'request_id' => $context->requestId,
                'session_public_id' => $context->sessionPublicId(),
            ] + $context->attributes,
        );
    }

    /**
     * @param array<string, mixed> $principal
     */
    private function fromArray(array $principal): PrincipalInterface
    {
        $id = $principal['id'] ?? $principal['identifier'] ?? $principal['uuid'] ?? null;

        if ($id === null || trim((string) $id) === '') {
            return new AnonymousPrincipal();
        }

        $type = isset($principal['type']) && is_string($principal['type'])
            ? $this->principalType($principal['type'])
            : PrincipalType::User;

        $authenticated = isset($principal['authenticated'])
            ? (bool) $principal['authenticated']
            : $type !== PrincipalType::Anonymous;

        return new Principal((string) $id, $type, $authenticated, $principal);
    }

    private function fromObject(object $principal): PrincipalInterface
    {
        /** @var array<string, mixed> $claims */
        $claims = get_object_vars($principal);
        $type = isset($claims['type']) && is_string($claims['type'])
            ? $this->principalType($claims['type'])
            : PrincipalType::User;

        $id = null;

        foreach (['authorizationIdentifier', 'id', 'identifier', 'uuid'] as $property) {
            if (array_key_exists($property, $claims) && is_scalar($claims[$property])) {
                $id = (string) $claims[$property];
                break;
            }
        }

        if ($id === null) {
            foreach (['authorizationIdentifier', 'id', 'identifier', 'uuid'] as $method) {
                if (method_exists($principal, $method)) {
                    $value = $principal->{$method}();

                    if (is_scalar($value)) {
                        $id = (string) $value;
                        break;
                    }
                }
            }
        }

        if ($id === null || trim($id) === '') {
            return new AnonymousPrincipal();
        }

        return new Principal($id, $type, $type !== PrincipalType::Anonymous, $claims);
    }

    private function principalType(string $type): PrincipalType
    {
        return PrincipalType::tryFrom(trim($type)) ?? PrincipalType::User;
    }
}
