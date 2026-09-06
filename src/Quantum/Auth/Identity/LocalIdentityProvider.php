<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface;
use Quantum\Config\ConfigRepository;

final class LocalIdentityProvider implements IdentityProviderInterface, PasswordRehashingIdentityProviderInterface
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

    public function upgradePasswordHash(IdentityInterface $identity, string $passwordHash): bool
    {
        if (trim($passwordHash) === '') {
            return false;
        }

        $identities = $this->configuredIdentities();
        $updated = false;

        foreach ($identities as $index => $entry) {
            if (! is_array($entry) || ! $this->matchesIdentity($entry, $identity)) {
                continue;
            }

            $entry['password_hash'] = $passwordHash;
            $identities[$index] = $entry;
            $updated = true;
            break;
        }

        if (! $updated) {
            return false;
        }

        $this->config->set('auth.providers.local.identities', $identities);

        $storagePath = $this->storagePath();

        if ($storagePath === null) {
            return true;
        }

        return $this->persistStoredIdentities($storagePath, $identities);
    }

    /**
     * @return array<int, mixed>
     */
    private function configuredIdentities(): array
    {
        $storagePath = $this->storagePath();

        if ($storagePath !== null) {
            $stored = $this->loadStoredIdentities($storagePath);

            if ($stored !== null) {
                return $stored;
            }
        }

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
            if (is_array($entry) && $this->matchesIdentity($entry, $identity)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function matchesIdentity(array $entry, IdentityInterface $identity): bool
    {
        if ((string) ($entry['id'] ?? '') === (string) $identity->identifier()) {
            return true;
        }

        if (! $identity instanceof GenericIdentity) {
            return false;
        }

        $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

        if (! is_string($lookupIdentifier) || trim($lookupIdentifier) === '') {
            return false;
        }

        $normalizedLookup = strtolower(trim($lookupIdentifier));

        foreach (['identifier', 'email', 'username'] as $key) {
            $candidate = isset($entry[$key]) ? strtolower(trim((string) $entry[$key])) : '';

            if ($candidate !== '' && $candidate === $normalizedLookup) {
                return true;
            }
        }

        return false;
    }

    private function storagePath(): ?string
    {
        $path = $this->config->get('auth.providers.local.storage_path');

        return is_string($path) && trim($path) !== ''
            ? trim($path)
            : null;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function loadStoredIdentities(string $storagePath): ?array
    {
        if (! is_file($storagePath)) {
            return null;
        }

        $contents = file_get_contents($storagePath);

        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        $identities = $decoded['identities'] ?? $decoded;

        return is_array($identities) ? array_values($identities) : null;
    }

    /**
     * @param array<int, mixed> $identities
     */
    private function persistStoredIdentities(string $storagePath, array $identities): bool
    {
        $directory = dirname($storagePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return false;
        }

        $payload = json_encode(
            ['identities' => array_values($identities)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($payload)) {
            return false;
        }

        return file_put_contents($storagePath, $payload . PHP_EOL) !== false;
    }
}
