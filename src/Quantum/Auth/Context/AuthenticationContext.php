<?php

declare(strict_types=1);

namespace Quantum\Auth\Context;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Support\AuthenticationAssurance;
use Quantum\Controllers\Security\Context\AuthenticationStrength;

final readonly class AuthenticationContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public IdentityInterface $identity,
        public IdentityReference $reference,
        public string $requestId,
        public string $method = 'manual',
        public array $attributes = [],
    ) {}

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function authenticationStrength(): AuthenticationStrength
    {
        return AuthenticationAssurance::resolveStrength($this->attributes, $this->method);
    }

    public function authenticationAssuranceProfile(): string
    {
        $profile = $this->attribute('authentication_assurance_profile');

        if (is_string($profile) && trim($profile) !== '') {
            return trim($profile);
        }

        return AuthenticationAssurance::profileFor($this->authenticationStrength());
    }
}
