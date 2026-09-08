<?php

declare(strict_types=1);

namespace Quantum\Auth\Credentials;

final readonly class PasswordCredentials
{
    public function __construct(
        public string $identifier,
        public string $password,
        public ?string $secondFactor = null,
    ) {}

    /**
     * @param array<string, mixed> $credentials
     */
    public static function fromArray(array $credentials): ?self
    {
        $identifier = self::firstNonEmpty($credentials, ['identifier', 'email', 'username', 'login']);
        $password = isset($credentials['password']) ? trim((string) $credentials['password']) : '';
        $secondFactor = self::firstOptionalNonEmpty($credentials, ['second_factor', 'mfa_code', 'otp', 'code']);

        if ($identifier === '' || $password === '') {
            return null;
        }

        return new self(
            identifier: $identifier,
            password: $password,
            secondFactor: $secondFactor,
        );
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function secondFactorFromArray(array $credentials): ?string
    {
        return self::firstOptionalNonEmpty($credentials, ['second_factor', 'mfa_code', 'otp', 'code']);
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

    /**
     * @param array<string, mixed> $credentials
     * @param list<string> $keys
     */
    private static function firstOptionalNonEmpty(array $credentials, array $keys): ?string
    {
        $value = self::firstNonEmpty($credentials, $keys);

        return $value !== '' ? $value : null;
    }
}
