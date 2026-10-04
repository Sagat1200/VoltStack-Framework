<?php

declare(strict_types=1);

namespace Quantum\Config\Diagnostics;

use DateTimeInterface;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;

final class ConfigRedactor
{
    /**
     * @param list<string> $sensitiveFragments
     */
    public function __construct(
        private readonly array $sensitiveFragments = [
            'password',
            'secret',
            'token',
            'key',
            'credential',
            'authorization',
            'cookie',
            'bearer',
            'webhook_headers',
        ],
        private readonly int $maxDepth = 6,
        private readonly int $maxItems = 200,
        private readonly int $maxStringBytes = 2048,
    ) {
    }

    public function redact(mixed $value, ?string $path = null, int $depth = 0): mixed
    {
        if ($depth >= $this->maxDepth) {
            return '[depth-exceeded]';
        }

        if ($value instanceof EnvReference) {
            return [
                '_type' => 'env_reference',
                'name' => $value->name(),
                'required' => $value->required(),
                'default' => $value->default() !== null ? '[default-configured]' : null,
            ];
        }

        if ($value instanceof SecretReference) {
            return [
                '_type' => 'secret_reference',
                'key' => $value->key(),
                'provider' => $value->provider(),
                'required' => $value->required(),
                'value' => '[redacted]',
            ];
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if ($this->isSensitivePath($path)) {
                return '[redacted]';
            }

            if (strlen($value) <= $this->maxStringBytes) {
                return $value;
            }

            return substr($value, 0, $this->maxStringBytes) . '...';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            $items = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $count++;

                if ($count > $this->maxItems) {
                    $items['_truncated'] = true;
                    break;
                }

                $normalizedKey = is_int($key) ? (string) $key : (string) $key;
                $childPath = $path === null || $path === ''
                    ? $normalizedKey
                    : $path . '.' . $normalizedKey;

                if (
                    $this->isSensitivePath($childPath)
                    && ! is_array($item)
                    && ! $item instanceof EnvReference
                    && ! $item instanceof SecretReference
                ) {
                    $items[is_int($key) ? $key : $normalizedKey] = '[redacted]';
                    continue;
                }

                $items[is_int($key) ? $key : $normalizedKey] = $this->redact($item, $childPath, $depth + 1);
            }

            return $items;
        }

        if (is_object($value)) {
            return [
                '_type' => $value::class,
            ];
        }

        return '[unserializable]';
    }

    private function isSensitivePath(?string $path): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }

        $normalized = strtolower($path);

        foreach ($this->sensitiveFragments as $fragment) {
            if (str_contains($normalized, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }
}
