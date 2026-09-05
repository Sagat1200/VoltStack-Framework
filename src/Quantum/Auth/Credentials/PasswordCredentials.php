<?php

declare(strict_types=1);

namespace Quantum\Auth\Credentials;

final readonly class PasswordCredentials
{
    public function __construct(
        public string $identifier,
        public string $password,
    ) {
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function fromArray(array $credentials): ?self
    {
        $identifier = self::firstNonEmpty($credentials, ['identifier', 'email', 'username', 'login']);
        $password = isset($credentials['password']) ? trim((string) $credentials['password']) : '';

        if ($identifier === '' || $password === '') {
            return null;
        }

        return new self(
            identifier: $identifier,
            password: $password,
        );
    }

    /**
     * @param array<string, mixed> $credentials
     * @param list<string> $keys
     */
    private static function firstNonEmpty(array $credentials, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $credentials)) {
                continue;
            }

            $value = trim((string) $credentials[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
