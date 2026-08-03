<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Schema\MetadataSchema;

final class MetadataMerger
{
    public function merge(array $fragments, callable $schemaResolver): array
    {
        $grouped = [];

        foreach ($fragments as $fragment) {
            $grouped[$fragment->key][] = $fragment;
        }

        $result = [];

        foreach ($grouped as $key => $items) {
            usort($items, static fn (MetadataFragment $a, MetadataFragment $b): int => $b->priority <=> $a->priority);

            $schema = $schemaResolver($key);
            $result[$key] = $this->mergeKey($items, $schema);
        }

        return $result;
    }

    private function mergeKey(array $fragments, ?MetadataSchema $schema): mixed
    {
        if ($schema !== null && $schema->final) {
            return $fragments[0]->value;
        }

        if ($schema === null || $schema->merge === MetadataMergeStrategy::Replace) {
            foreach ($fragments as $fragment) {
                if ($fragment->final) {
                    return $fragment->value;
                }
            }

            return $fragments[0]->value;
        }

        $value = $schema->defaultValue;

        foreach (array_reverse($fragments) as $fragment) {
            if ($fragment->final) {
                $value = $fragment->value;
                continue;
            }

            if (! is_array($value)) {
                $value = [];
            }

            if (! is_array($fragment->value)) {
                continue;
            }

            $value = [...$value, ...$fragment->value];
        }

        return $value;
    }
}
