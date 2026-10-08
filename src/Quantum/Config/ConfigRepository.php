<?php

declare(strict_types=1);

namespace Quantum\Config;

use Quantum\Config\Diagnostics\ConfigRedactor;
use Quantum\Config\Loading\PhpConfigLoader;
use Quantum\Config\Reference\ConfigReferenceResolver;
use Quantum\Config\Schema\ConfigSchema;
use Quantum\Config\Schema\ConfigSchemaRegistry;
use Quantum\Config\Validation\ConfigValidationResult;
use Quantum\Config\Validation\ConfigValidator;

final class ConfigRepository
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    /**
     * @var list<ConfigDocument>
     */
    private array $documents = [];

    /**
     * @var array<string, mixed>
     */
    private array $provenance = [];

    /**
     * @var array<int, callable(self): void>
     */
    private array $mutationListeners = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param callable(self): void $listener
     */
    public function onMutation(callable $listener): void
    {
        $this->mutationListeners[] = $listener;
    }

    /**
     * @return list<ConfigDocument>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    public function has(string $key): bool
    {
        return $this->get($key, MissingValue::Token) !== MissingValue::Token;
    }

    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->all();
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
        $this->notifyMutationListeners();
    }

    public function replace(array $items): void
    {
        $this->items = $items;
        $this->documents = [];
        $this->provenance = [];
        $this->notifyMutationListeners();
    }

    public function loadSnapshot(ConfigSnapshot $snapshot): void
    {
        $this->items = $snapshot->all();
        $this->documents = [];
        $this->provenance = $snapshot->provenance();
        $this->notifyMutationListeners();
    }

    public function hasPath(ConfigPath $path): bool
    {
        return $this->getPath($path, MissingValue::Token) !== MissingValue::Token;
    }

    public function getPath(ConfigPath $path, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach ($path->segments() as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function setPath(ConfigPath $path, mixed $value): void
    {
        $target = &$this->items;

        foreach ($path->segments() as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
        $this->notifyMutationListeners();
    }

    /**
     * @param array<string, mixed> $provenance
     */
    public function snapshot(
        ?array $provenance = null,
        ?string $schemaHash = null,
        ?string $configId = null,
    ): ConfigSnapshot {
        return new ConfigSnapshot(
            data: $this->items,
            provenance: $provenance ?? $this->provenance,
            schemaHash: $schemaHash,
            configId: $configId,
        );
    }

    public function document(
        SourceDescriptor $descriptor,
        string $schemaVersion = '1.0.0',
    ): ConfigDocument {
        $payload = $this->get($descriptor->namespace(), []);

        return new ConfigDocument(
            descriptor: $descriptor,
            payload: is_array($payload) ? $payload : [],
            schemaVersion: $schemaVersion,
        );
    }

    public function resolveReferences(
        ?string $key = null,
        ?ConfigReferenceResolver $resolver = null,
    ): mixed {
        $resolver ??= new ConfigReferenceResolver();

        return $resolver->resolve($this->get($key));
    }

    /**
     * @return array<string, mixed>
     */
    public function redact(
        ?string $key = null,
        ?ConfigRedactor $redactor = null,
    ): array {
        $redactor ??= new ConfigRedactor();
        $value = $key === null ? $this->all() : $this->get($key);
        $path = $key === null ? null : $key;
        $redacted = $redactor->redact($value, $path);

        if (is_array($redacted)) {
            return $redacted;
        }

        if ($key === null) {
            return [];
        }

        return [$key => $redacted];
    }

    public function validateSchema(
        ConfigSchema $schema,
        ?ConfigValidator $validator = null,
    ): ConfigValidationResult {
        $payload = $this->get($schema->namespace(), []);

        return ($validator ?? new ConfigValidator())->validate(
            $schema,
            is_array($payload) ? $payload : [],
        );
    }

    public function validateRegistered(
        string $namespace,
        ConfigSchemaRegistry $registry,
        ?ConfigValidator $validator = null,
    ): ConfigValidationResult {
        return $this->validateSchema(
            $registry->get($namespace),
            $validator,
        );
    }

    public function loadPath(string $configPath): void
    {
        $loaded = (new PhpConfigLoader())->loadPath($configPath);

        foreach ($loaded->items() as $key => $config) {
            $this->items[$key] = $config;
        }

        foreach ($loaded->provenance() as $key => $location) {
            $this->provenance[$key] = $location;
        }

        $this->documents = $loaded->documents();
        $this->notifyMutationListeners();
    }

    private function notifyMutationListeners(): void
    {
        foreach ($this->mutationListeners as $listener) {
            $listener($this);
        }
    }
}
