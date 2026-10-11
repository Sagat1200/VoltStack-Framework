<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\FederatedLinkNonceStoreInterface;

/**
 * Implementación en memoria del almacén de nonces de FederatedLink.
 *
 * Útil para tests y entornos efímeros. Los nonces se almacenan con su
 * timestamp de consumo para permitir purga.
 */
final class InMemoryFederatedLinkNonceStore implements FederatedLinkNonceStoreInterface
{
    /**
     * @var array<string, int> nonce => consumedAt timestamp
     */
    private array $consumed = [];

    public function consume(string $nonce): bool
    {
        $key = $this->normalize($nonce);

        if ($key === '') {
            return false;
        }

        if (isset($this->consumed[$key])) {
            return false;
        }

        $this->consumed[$key] = time();

        return true;
    }

    public function isConsumed(string $nonce): bool
    {
        $key = $this->normalize($nonce);

        return $key !== '' && isset($this->consumed[$key]);
    }

    public function purge(int $beforeTimestamp): int
    {
        $count = 0;
        foreach ($this->consumed as $nonce => $consumedAt) {
            if ($consumedAt < $beforeTimestamp) {
                unset($this->consumed[$nonce]);
                $count++;
            }
        }

        return $count;
    }

    private function normalize(string $nonce): string
    {
        return trim($nonce);
    }
}
