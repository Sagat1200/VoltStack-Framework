<?php

declare(strict_types=1);

namespace Quantum\Config\Publication;

use DateTimeInterface;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigSnapshot;
use RuntimeException;

final class ConfigSnapshotCodec
{
    /**
     * @return array{
     *     data: array<string, mixed>,
     *     provenance: array<string, mixed>,
     *     schema_hash: ?string,
     *     config_id: string
     * }
     */
    public function encode(ConfigSnapshot $snapshot): array
    {
        $configId = $snapshot->configId() ?? $this->configId($snapshot);

        return [
            'data' => $this->serialize($snapshot->all()),
            'provenance' => $this->serialize($snapshot->provenance()),
            'schema_hash' => $snapshot->schemaHash(),
            'config_id' => $configId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function decode(array $payload): ConfigSnapshot
    {
        $data = $payload['data'] ?? [];
        $provenance = $payload['provenance'] ?? [];

        if (! is_array($data) || ! is_array($provenance)) {
            throw new RuntimeException('Configuration snapshot payload is invalid.');
        }

        return new ConfigSnapshot(
            data: $this->deserialize($data),
            provenance: $this->deserialize($provenance),
            schemaHash: isset($payload['schema_hash']) && is_string($payload['schema_hash']) ? $payload['schema_hash'] : null,
            configId: isset($payload['config_id']) && is_string($payload['config_id']) ? $payload['config_id'] : null,
        );
    }

    public function configId(ConfigSnapshot $snapshot): string
    {
        $payload = [
            'data' => $this->serialize($snapshot->all()),
            'provenance' => $this->serialize($snapshot->provenance()),
            'schema_hash' => $snapshot->schemaHash(),
        ];

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $encoded === false ? '{}' : $encoded);
    }

    private function serialize(mixed $value): mixed
    {
        if ($value instanceof EnvReference || $value instanceof SecretReference) {
            return $value->toArray();
        }

        if ($value instanceof DateTimeInterface) {
            return [
                'type' => 'datetime',
                'value' => $value->format(DATE_ATOM),
            ];
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

    private function deserialize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $type = $value['type'] ?? null;

        if ($type === 'env' && isset($value['name']) && is_string($value['name'])) {
            return new EnvReference(
                name: $value['name'],
                default: $this->deserialize($value['default'] ?? null),
                required: (bool) ($value['required'] ?? false),
            );
        }

        if ($type === 'secret' && isset($value['key']) && is_string($value['key'])) {
            return new SecretReference(
                key: $value['key'],
                provider: isset($value['provider']) && is_string($value['provider']) ? $value['provider'] : 'env',
                required: (bool) ($value['required'] ?? true),
            );
        }

        if ($type === 'datetime' && isset($value['value']) && is_string($value['value'])) {
            return $value['value'];
        }

        $deserialized = [];

        foreach ($value as $key => $item) {
            $deserialized[$key] = $this->deserialize($item);
        }

        return $deserialized;
    }
}
