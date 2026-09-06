<?php

declare(strict_types=1);

namespace Quantum\Auth\Context;

use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Support\AuthenticationAssurance;
use RuntimeException;
use VoltStack\Runtime\Context\RuntimeContext;

final class AuthenticationContextAccessor
{
    private const CONTEXT_KEY = 'auth.context';
    private const LEGACY_USER_KEY = 'auth.user';

    public function get(): ?AuthenticationContext
    {
        $context = $this->runtimeContext();
        $resolved = $context->get(self::CONTEXT_KEY);

        if ($resolved instanceof AuthenticationContext) {
            return $resolved;
        }

        $legacyUser = $context->get(self::LEGACY_USER_KEY);

        if ($legacyUser === null) {
            return null;
        }

        $authenticationContext = $this->createFromUser($legacyUser, $context->requestId());
        $context->set(self::CONTEXT_KEY, $authenticationContext);

        return $authenticationContext;
    }

    public function put(AuthenticationContext $authenticationContext): void
    {
        $context = $this->runtimeContext();
        $context->set(self::CONTEXT_KEY, $authenticationContext);
        $context->set(self::LEGACY_USER_KEY, $authenticationContext->identity);
    }

    public function putUser(mixed $user): void
    {
        $context = $this->runtimeContext();

        if ($user === null) {
            $context->set(self::LEGACY_USER_KEY, null);
            $context->set(self::CONTEXT_KEY, null);

            return;
        }

        $authenticationContext = $this->createFromUser($user, $context->requestId());
        $context->set(self::LEGACY_USER_KEY, $user);
        $context->set(self::CONTEXT_KEY, $authenticationContext);
    }

    public function clear(): void
    {
        $context = $this->runtimeContext();
        $context->set(self::LEGACY_USER_KEY, null);
        $context->set(self::CONTEXT_KEY, null);
    }

    private function createFromUser(mixed $user, string $requestId): AuthenticationContext
    {
        $identity = $this->normalizeIdentity($user);

        return new AuthenticationContext(
            identity: $identity,
            reference: new IdentityReference(
                identifier: $identity->identifier(),
                type: $identity->type(),
            ),
            requestId: $requestId,
            method: 'manual',
            attributes: AuthenticationAssurance::enrichAttributes([], 'manual'),
        );
    }

    private function normalizeIdentity(mixed $user): IdentityInterface
    {
        if ($user instanceof IdentityInterface) {
            return $user;
        }

        if (is_array($user) && array_key_exists('id', $user)) {
            return new GenericIdentity(
                identifier: new IdentityIdentifier((string) $user['id']),
                type: 'array',
                attributes: $user + ['_legacy_id' => $user['id']],
            );
        }

        if (is_object($user) && isset($user->id)) {
            /** @var object{ id:mixed } $user */
            return new GenericIdentity(
                identifier: new IdentityIdentifier((string) $user->id),
                type: 'object',
                attributes: get_object_vars($user) + ['_legacy_id' => $user->id],
            );
        }

        return new GenericIdentity(
            identifier: new IdentityIdentifier(md5(serialize($user))),
            type: 'anonymous_payload',
            attributes: ['value' => $user],
        );
    }

    private function runtimeContext(): RuntimeContext
    {
        $context = RuntimeContext::current();

        if ($context === null) {
            throw new RuntimeException('No active runtime context is available for auth access.');
        }

        return $context;
    }
}
