<?php

declare(strict_types=1);

namespace Quantum\Config\Validation;

use Quantum\Config\MissingValue;
use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;

final class ConfigValidator
{
    /**
     * @var list<ConfigViolation>
     */
    private array $violations = [];

    /**
     * @param array<string, mixed> $input
     */
    public function validate(ConfigSchema $schema, array $input): ConfigValidationResult
    {
        $this->violations = [];

        $validated = $this->validateNode(
            node: $schema->root(),
            exists: true,
            value: $input,
            path: $schema->namespace(),
        );

        return new ConfigValidationResult(
            data: is_array($validated) ? $validated : [],
            violations: $this->violations,
        );
    }

    private function validateNode(ConfigNode $node, bool $exists, mixed $value, string $path): mixed
    {
        if (! $exists) {
            if ($node->hasDefault()) {
                $exists = true;
                $value = $node->default();
            } else {
                if ($node->required()) {
                    $this->violations[] = new ConfigViolation($path, 'required', sprintf('Configuration path [%s] is required.', $path));
                }

                return MissingValue::Token;
            }
        }

        if ($value === null) {
            if ($node->nullable()) {
                return null;
            }

            $this->violations[] = new ConfigViolation($path, 'null_not_allowed', sprintf('Configuration path [%s] does not allow null.', $path));

            return null;
        }

        return match ($node->type()) {
            'string' => $this->validateString($value, $path),
            'integer' => $this->validateInteger($value, $path),
            'number' => $this->validateNumber($value, $path),
            'boolean' => $this->validateBoolean($value, $path),
            'enum' => $this->validateEnum($node, $value, $path),
            'map' => $this->validateMap($node, $value, $path),
            'dictionary' => $this->validateDictionary($node, $value, $path),
            'list' => $this->validateList($node, $value, $path),
            default => $this->fail($path, 'unknown_type', sprintf('Configuration node type [%s] is not supported.', $node->type())),
        };
    }

    private function validateString(mixed $value, string $path): mixed
    {
        if (! is_string($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a string.', $path));
        }

        return $value;
    }

    private function validateInteger(mixed $value, string $path): mixed
    {
        if (! is_int($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be an integer.', $path));
        }

        return $value;
    }

    private function validateNumber(mixed $value, string $path): mixed
    {
        if (! is_int($value) && ! is_float($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a number.', $path));
        }

        return $value;
    }

    private function validateBoolean(mixed $value, string $path): mixed
    {
        if (! is_bool($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a boolean.', $path));
        }

        return $value;
    }

    private function validateEnum(ConfigNode $node, mixed $value, string $path): mixed
    {
        foreach ($node->allowedValues() as $allowed) {
            if ($allowed === $value) {
                return $value;
            }
        }

        return $this->fail(
            $path,
            'invalid_enum',
            sprintf('Configuration path [%s] must be one of: %s.', $path, implode(', ', array_map(static fn(mixed $item): string => (string) $item, $node->allowedValues()))),
        );
    }

    private function validateMap(ConfigNode $node, mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a map.', $path));
        }

        $validated = [];
        $children = $node->children();

        foreach ($children as $childKey => $childNode) {
            $childExists = array_key_exists($childKey, $value);
            $childValue = $childExists ? $value[$childKey] : null;
            $resolved = $this->validateNode($childNode, $childExists, $childValue, $path . '.' . $childKey);

            if ($resolved !== MissingValue::Token) {
                $validated[$childKey] = $resolved;
            }
        }

        foreach ($value as $childKey => $childValue) {
            if (! is_string($childKey) || array_key_exists($childKey, $children)) {
                continue;
            }

            if ($node->allowUnknown()) {
                $validated[$childKey] = $childValue;
                continue;
            }

            $this->violations[] = new ConfigViolation(
                $path . '.' . $childKey,
                'unknown_key',
                sprintf('Configuration path [%s.%s] is not allowed.', $path, $childKey),
            );
        }

        return $validated;
    }

    private function validateDictionary(ConfigNode $node, mixed $value, string $path): mixed
    {
        if (! is_array($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a dictionary.', $path));
        }

        $valueNode = $node->valueNode();
        $validated = [];

        foreach ($value as $entryKey => $entryValue) {
            if (! is_string($entryKey) || trim($entryKey) === '') {
                $this->violations[] = new ConfigViolation($path, 'invalid_key', sprintf('Configuration dictionary [%s] contains an invalid key.', $path));
                continue;
            }

            $resolved = $this->validateNode($valueNode ?? ConfigNode::string(), true, $entryValue, $path . '.' . $entryKey);

            if ($resolved !== MissingValue::Token) {
                $validated[$entryKey] = $resolved;
            }
        }

        return $validated;
    }

    private function validateList(ConfigNode $node, mixed $value, string $path): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $this->fail($path, 'invalid_type', sprintf('Configuration path [%s] must be a list.', $path));
        }

        $itemNode = $node->itemNode();
        $validated = [];

        foreach ($value as $index => $item) {
            $resolved = $this->validateNode($itemNode ?? ConfigNode::string(), true, $item, $path . '.' . $index);

            if ($resolved !== MissingValue::Token) {
                $validated[] = $resolved;
            }
        }

        return $validated;
    }

    private function fail(string $path, string $code, string $message): null
    {
        $this->violations[] = new ConfigViolation($path, $code, $message);

        return null;
    }
}
