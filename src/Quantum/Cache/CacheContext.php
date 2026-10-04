<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;

final readonly class CacheContext
{
    /**
     * @param array<int, string> $tags
     */
    public function __construct(
        public string $keyPrefix = '',
        public ?string $versionScope = null,
        public array $tags = [],
        public DateInterval|DateTimeInterface|int|null $defaultTtl = null,
    ) {}

    /**
     * @param array<int, string>|string|null $keyPrefix
     * @param array<int, string>|string $tags
     */
    public static function from(
        array|string|null $keyPrefix = '',
        ?string $versionScope = null,
        array|string $tags = [],
        DateInterval|DateTimeInterface|int|null $defaultTtl = null,
    ): self {
        return new self(
            keyPrefix: self::normalizePrefix($keyPrefix),
            versionScope: self::normalizeScope($versionScope),
            tags: self::normalizeTags($tags),
            defaultTtl: $defaultTtl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key_prefix' => $this->keyPrefix,
            'version_scope' => $this->versionScope,
            'tags' => $this->tags,
            'default_ttl' => $this->defaultTtl,
        ];
    }

    /**
     * @param array<int, string>|string|null $prefix
     */
    public static function normalizePrefix(array|string|null $prefix): string
    {
        if ($prefix === null) {
            return '';
        }

        $segments = is_array($prefix)
            ? $prefix
            : (preg_split('/[:\/\\\\]+/', (string) $prefix) ?: []);
        $normalized = [];

        foreach ($segments as $segment) {
            $value = trim((string) $segment);

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return implode(':', $normalized);
    }

    public static function normalizeScope(?string $scope): ?string
    {
        $value = trim((string) $scope);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, string>|string $tags
     * @return array<int, string>
     */
    public static function normalizeTags(array|string $tags): array
    {
        $values = is_array($tags) ? $tags : [$tags];
        $normalized = [];

        foreach ($values as $tag) {
            $value = trim((string) $tag);

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
