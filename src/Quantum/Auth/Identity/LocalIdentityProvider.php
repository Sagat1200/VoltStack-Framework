<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Config\ConfigRepository;

final class LocalIdentityProvider implements IdentityProviderInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function findByIdentifier(string $identifier): ?IdentityInterface
    {
        $entry = $this->findEntry($identifier);

        if ($entry === null) {
            return null;
        }

        return new GenericIdentity(
            identifier: new IdentityIdentifier((string) ($entry['id'] ?? $identifier)),
            type: trim((string) ($entry['type'] ?? 'user')) !== '' ? trim((string) ($entry['type'] ?? 'user')) : 'user',
            attributes: $this->identityAttributes($entry, $identifier),
        );
    }

    public function passwordHashFor(IdentityInterface $identity): ?string
    {
        if ($identity instanceof GenericIdentity) {
            $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

            if (is_string($lookupIdentifier) && trim($lookupIdentifier) !== '') {
                $entry = $this->findEntry($lookupIdentifier);

                if ($entry !== null) {
                    $passwordHash = $entry['password_hash'] ?? null;

                    return is_string($passwordHash) && trim($passwordHash) !== '' ? $passwordHash : null;
                }
            }
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = (string) ($entry['id'] ?? '');
            if ($entryId !== '' && $entryId === (string) $identity->identifier()) {
                $passwordHash = $entry['password_hash'] ?? null;

                return is_string($passwordHash) && trim($passwordHash) !== '' ? $passwordHash : null;
            }
        }

        return null;
    }

    public function securityStateFor(IdentityInterface $identity): IdentitySecurityState
    {
        $entry = $this->entryForIdentity($identity);
        $state = strtolower(trim((string) ($entry['security_state'] ?? $entry['status'] ?? 'active')));

        return match ($state) {
            'disabled' => IdentitySecurityState::Disabled,
            'suspended' => IdentitySecurityState::Suspended,
            'locked' => IdentitySecurityState::Locked,
            default => IdentitySecurityState::Active,
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function configuredIdentities(): array
    {
        $identities = $this->config->get('auth.providers.local.identities', []);

        return is_array($identities) ? array_values($identities) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEntry(string $identifier): ?array
    {
        $normalized = strtolower(trim($identifier));

        if ($normalized === '') {
            return null;
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['identifier', 'email', 'username'] as $key) {
                $candidate = isset($entry[$key]) ? strtolower(trim((string) $entry[$key])) : '';

                if ($candidate !== '' && $candidate === $normalized) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function identityAttributes(array $entry, string $identifier): array
    {
        $attributes = [];

        foreach ($entry as $key => $value) {
            if (in_array($key, ['password_hash'], true)) {
                continue;
            }

            $attributes[$key] = $value;
        }

        $attributes['_provider_identifier_value'] = $identifier;

        return $attributes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryForIdentity(IdentityInterface $identity): ?array
    {
        if ($identity instanceof GenericIdentity) {
            $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

            if (is_string($lookupIdentifier) && trim($lookupIdentifier) !== '') {
                return $this->findEntry($lookupIdentifier);
            }
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ((string) ($entry['id'] ?? '') === (string) $identity->identifier()) {
                return $entry;
            }
        }

        return null;
    }
}
