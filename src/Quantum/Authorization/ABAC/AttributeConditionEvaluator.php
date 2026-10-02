<?php

declare(strict_types=1);

namespace Quantum\Authorization\ABAC;

use Quantum\Authorization\Authority\AttributeDefinition;
use Quantum\Authorization\Core\AuthorizationRequest;

/**
 * Evalua condiciones ABAC simples expresadas como uno o varios
 * AttributeDefinition serializados.
 *
 * Formatos soportados:
 * - array asociativo simple:
 *   ['attribute' => 'risk.score', 'type' => 'integer', 'max' => 50]
 * - array con constraints anidados:
 *   ['name' => 'department', 'type' => 'enum', 'constraints' => ['values' => ['sales']]]
 * - lista de definiciones; todas deben cumplirse.
 *
 * Resolución de valores:
 * - nombre plano o dot-path sobre AuthorizationContext::attributes()
 * - prefijo `context.` equivalente al root de attributes
 * - prefijo `subject.` contra SubjectDescriptor::value()
 */
final class AttributeConditionEvaluator
{
    public function evaluate(AuthorizationRequest $request, mixed $condition): bool
    {
        if ($condition === null) {
            return true;
        }

        $definitions = $this->normalizeDefinitions($condition);
        if ($definitions === []) {
            return false;
        }

        foreach ($definitions as $definition) {
            $value = $this->resolveValue($request, $definition->name);

            if (! $definition->accepts($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $condition
     * @return list<AttributeDefinition>
     */
    public function definitions(mixed $condition): array
    {
        return $this->normalizeDefinitions($condition);
    }

    /**
     * @param mixed $condition
     * @return list<AttributeDefinition>
     */
    private function normalizeDefinitions(mixed $condition): array
    {
        if ($condition instanceof Condition) {
            $definition = $this->definitionFromArray($condition->toArray());

            return $definition instanceof AttributeDefinition ? [$definition] : [];
        }

        if (! is_array($condition) || $condition === []) {
            return [];
        }

        if ($this->isAssoc($condition)) {
            $definition = $this->definitionFromArray($condition);

            return $definition instanceof AttributeDefinition ? [$definition] : [];
        }

        $definitions = [];

        foreach ($condition as $entry) {
            if ($entry instanceof Condition) {
                $definition = $this->definitionFromArray($entry->toArray());
                if ($definition instanceof AttributeDefinition) {
                    $definitions[] = $definition;
                }

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $definition = $this->definitionFromArray($entry);
            if ($definition instanceof AttributeDefinition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function definitionFromArray(array $data): ?AttributeDefinition
    {
        $name = trim((string) ($data['name'] ?? $data['attribute'] ?? ''));
        if ($name === '') {
            return null;
        }

        $type = isset($data['type']) && is_string($data['type']) && trim($data['type']) !== ''
            ? trim($data['type'])
            : AttributeDefinition::TYPE_ANY;

        $constraints = isset($data['constraints']) && is_array($data['constraints'])
            ? $data['constraints']
            : [];

        foreach (['values', 'pattern', 'min', 'max', 'required', 'default'] as $key) {
            if (array_key_exists($key, $data) && ! array_key_exists($key, $constraints)) {
                $constraints[$key] = $data[$key];
            }
        }

        $description = isset($data['description']) && is_string($data['description'])
            ? $data['description']
            : null;

        return new AttributeDefinition(
            name: $name,
            type: $type,
            constraints: $constraints,
            description: $description,
        );
    }

    private function resolveValue(AuthorizationRequest $request, string $path): mixed
    {
        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            return null;
        }

        $contextAttributes = $request->context()->attributes();

        if (array_key_exists($normalizedPath, $contextAttributes)) {
            return $contextAttributes[$normalizedPath];
        }

        if (str_starts_with($normalizedPath, 'context.')) {
            return $this->resolveDotPath($contextAttributes, substr($normalizedPath, 8));
        }

        if (str_starts_with($normalizedPath, 'subject.')) {
            return $this->resolveDotPath($request->subject()->value(), substr($normalizedPath, 8));
        }

        return $this->resolveDotPath($contextAttributes, $normalizedPath);
    }

    private function resolveDotPath(mixed $source, string $path): mixed
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $source;
        }

        $segments = explode('.', $trimmed);
        $current = $source;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_object($current) && isset($current->{$segment})) {
                $current = $current->{$segment};
                continue;
            }

            if (is_object($current)) {
                $getter = 'get' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $segment)));
                if (method_exists($current, $getter)) {
                    $current = $current->{$getter}();
                    continue;
                }
            }

            return null;
        }

        return $current;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
