<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

use Quantum\Auth\Contracts\FederatedIdentityLinkProviderInterface;

/**
 * Implementación local: usa los registros de `LocalIdentityProvider` y lee
 * la entrada `federated_links[]` de cada entrada. Cada link tiene formato:
 *
 *     [
 *         "rp_id"  => "accounts.example.com",   // obligatorio
 *         "issuer" => "https://accounts.example.com", // opcional
 *         "sub"    => "user|12345|abcde",         // obligatorio (subject persistente)
 *     ]
 *
 * No persiste cambios: es un read-only binding. Para mutaciones de links
 * (add/remove federado) se espera un service dedicado (fuera scope recovery).
 */
final class LocalFederatedIdentityLinkProvider implements FederatedIdentityLinkProviderInterface
{
    public function __construct(
        private readonly LocalIdentityProvider $provider,
    ) {
    }

    public function findByFederatedPair(string $rpId, string $subject): ?IdentityReference
    {
        $normalizedRpId = trim($rpId);
        $normalizedSubject = trim($subject);
        if ($normalizedRpId === '' || $normalizedSubject === '') {
            return null;
        }

        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('configuredIdentities');
        $method->setAccessible(true);
        $entries = $method->invoke($this->provider);
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $links = $entry['federated_links'] ?? [];
            if (! is_array($links)) {
                continue;
            }
            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $linkRp = trim((string) ($link['rp_id'] ?? $link['issuer'] ?? ''));
                $linkSub = trim((string) ($link['sub'] ?? $link['subject'] ?? ''));
                if ($linkRp !== '' && $linkSub !== ''
                    && ($linkRp === $normalizedRpId || trim((string) ($link['issuer'] ?? '')) === $normalizedRpId)
                    && $linkSub === $normalizedSubject) {
                    $entryId = (string) ($entry['id'] ?? '');
                    if ($entryId === '') {
                        $entryId = (string) ($entry['identifier'] ?? $link['sub']);
                    }

                    return new IdentityReference(
                        new IdentityIdentifier($entryId),
                        trim((string) ($entry['type'] ?? 'user')) !== '' ? trim((string) ($entry['type'] ?? 'user')) : 'user',
                    );
                }
            }
        }

        return null;
    }

    public function isLinkedTo(IdentityReference $identity, string $rpId, string $subject): bool
    {
        $found = $this->findByFederatedPair($rpId, $subject);
        if ($found === null) {
            return false;
        }

        return (string) $found->identifier === (string) $identity->identifier
            && $found->type === $identity->type;
    }
}
