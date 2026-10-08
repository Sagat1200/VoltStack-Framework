<?php

declare(strict_types=1);

namespace Quantum\Auth\Credentials;

final readonly class PasswordCredentials
{
    public function __construct(
        public string $identifier,
        public string $password,
        public ?string $secondFactor = null,
        public ?string $secondFactorMethod = null,
    ) {}

    /**
     * @param array<string, mixed> $credentials
     */
    public static function fromArray(array $credentials): ?self
    {
        $identifier = self::firstNonEmpty($credentials, ['identifier', 'email', 'username', 'login']);
        $password = isset($credentials['password']) ? trim((string) $credentials['password']) : '';
        $secondFactor = self::secondFactorFromArray($credentials);

        if ($identifier === '' || $password === '') {
            return null;
        }

        return new self(
            identifier: $identifier,
            password: $password,
            secondFactor: $secondFactor,
            secondFactorMethod: self::secondFactorMethodFromArray($credentials),
        );
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function secondFactorFromArray(array $credentials): ?string
    {
        return self::firstOptionalNonEmpty($credentials, [
            'recovery_code',
            'totp',
            'second_factor',
            'mfa_code',
            'otp',
            'code',
        ]);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function secondFactorMethodFromArray(array $credentials): ?string
    {
        $mechanism = isset($credentials['mechanism']) ? strtolower(trim((string) $credentials['mechanism'])) : '';
        if (in_array($mechanism, ['totp', 'recovery_code', 'second_factor'], true)) {
            return $mechanism;
        }

        if (self::firstOptionalNonEmpty($credentials, ['recovery_code']) !== null) {
            return 'recovery_code';
        }

        if (self::firstOptionalNonEmpty($credentials, ['totp', 'otp']) !== null) {
            return 'totp';
        }

        if (self::firstOptionalNonEmpty($credentials, ['second_factor', 'mfa_code', 'code']) !== null) {
            return 'second_factor';
        }

        return null;
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
