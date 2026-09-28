<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

final class CredentialStuffingBloomFilter
{
    /**
     * @var array<string, true>
     */
    private array $knownCompromised = [];

    /**
     * @param list<string> $additionalCompromisedPasswords
     */
    public function __construct(array $additionalCompromisedPasswords = [])
    {
        $defaultCompromised = [
            'password', '123456', '12345678', 'qwerty', 'abc123',
            'monkey', '1234567', 'letmein', 'trustno1', 'dragon',
            'password1', 'iloveyou', 'sunshine', 'princess', 'admin',
            'welcome', 'shadow', 'superman', 'michael', 'ninja',
            'mustang', 'jessica', 'charlie', 'ashley', 'bailey',
            'passw0rd', 'master', '654321', 'football', 'jordan',
            'soccer', 'harley', 'ranger', 'buster', 'thomas',
            'tigger', 'robert', 'access', 'love', 'killer',
        ];

        foreach (array_merge($defaultCompromised, $additionalCompromisedPasswords) as $pwd) {
            $hash = $this->hash($pwd);
            $this->knownCompromised[$hash] = true;
        }
    }

    public function isProbablyCompromised(string $rawPassword): bool
    {
        $hash = $this->hash($rawPassword);
        return isset($this->knownCompromised[$hash]);
    }

    private function hash(string $rawPassword): string
    {
        return hash('sha256', mb_strtolower(trim($rawPassword)));
    }
}
