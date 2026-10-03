<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Discovery;

use InvalidArgumentException;

final readonly class ProviderMetadata
{
    /**
     * @param list<string> $requires
     * @param list<string> $before
     * @param list<string> $after
     * @param list<string> $conflicts
     * @param list<string> $environments
     * @param list<string> $profiles
     */
    public function __construct(
        private string $id,
        private string $version = '1',
        private array $requires = [],
        private array $before = [],
        private array $after = [],
        private array $conflicts = [],
        private array $environments = [],
        private array $profiles = [],
        private int $priority = 0,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('ProviderMetadata id cannot be empty.');
        }
    }

    public static function defaultFor(string $providerClass): self
    {
        $providerClass = ltrim(trim($providerClass), '\\');

        if ($providerClass === '') {
            throw new InvalidArgumentException('Provider class cannot be empty when building default metadata.');
        }

        return new self(id: $providerClass);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return $this->requires;
    }

    /**
     * @return list<string>
     */
    public function before(): array
    {
        return $this->before;
    }

    /**
     * @return list<string>
     */
    public function after(): array
    {
        return $this->after;
    }

    /**
     * @return list<string>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return list<string>
     */
    public function environments(): array
    {
        return $this->environments;
    }

    /**
     * @return list<string>
     */
    public function profiles(): array
    {
        return $this->profiles;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function appliesTo(?string $environment, ?string $profile): bool
    {
        if ($this->environments !== [] && $environment !== null && ! in_array($environment, $this->environments, true)) {
            return false;
        }

        if ($this->profiles !== [] && $profile !== null && ! in_array($profile, $this->profiles, true)) {
            return false;
        }

        if ($this->environments !== [] && $environment === null) {
            return false;
        }

        if ($this->profiles !== [] && $profile === null) {
            return false;
        }

        return true;
    }
}
