<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Config;

use JsonException;

final readonly class BootstrapConfiguration
{
    /**
     * @param array<string, mixed> $structural
     * @param array<string, mixed> $operational
     * @param array<string, mixed> $secrets
     */
    public function __construct(
        private array $structural = [],
        private array $operational = [],
        private array $secrets = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $structural
     * @param array<string, mixed> $operational
     * @param array<string, mixed> $secrets
     */
    public static function fromSections(
        array $structural = [],
        array $operational = [],
        array $secrets = [],
    ): self {
        return new self(
            structural: $structural,
            operational: $operational,
            secrets: $secrets,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function structural(): array
    {
        return $this->structural;
    }

    /**
     * @return array<string, mixed>
     */
    public function operational(): array
    {
        return $this->operational;
    }

    /**
     * @return array<string, mixed>
     */
    public function secrets(): array
    {
        return $this->secrets;
    }

    /**
     * @return array{
     *     structural: array<string, mixed>,
     *     operational: array<string, mixed>,
     *     secrets: array<string, mixed>
     * }
     */
    public function toManifestPayload(): array
    {
        return [
            'structural' => $this->serialize($this->structural),
            'operational' => $this->serialize($this->operational),
            'secrets' => $this->serialize($this->secrets),
        ];
    }

    public function fingerprint(): string
    {
        try {
            $encoded = json_encode(
                $this->toManifestPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $encoded = '{}';
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function serialize(mixed $value): mixed
    {
        if ($value instanceof EnvReference || $value instanceof SecretReference) {
            return $value->toArray();
        }

        if (is_array($value)) {
            $serialized = [];

            foreach ($value as $key => $item) {
                $serialized[$key] = $this->serialize($item);
            }

            return $serialized;
        }

        return $value;
    }
}
