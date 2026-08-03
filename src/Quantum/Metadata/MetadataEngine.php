<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\Contracts\MetadataSubjectInterface;
use Quantum\Metadata\MetadataMerger;
use Quantum\Metadata\Schema\MetadataSchemaRegistry;

final class MetadataEngine implements MetadataEngineInterface
{
    /**
     * @var array<string, MetadataBag>
     */
    private array $cache = [];

    public function __construct(
        private readonly MetadataProviderPipeline $pipeline,
        private readonly MetadataSchemaRegistry $schemas,
        private readonly MetadataNormalizer $normalizer,
        private readonly MetadataMerger $merger,
    ) {}

    public function resolve(MetadataRequest $request): MetadataBag
    {
        $cacheKey = $this->cacheKey($request);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $fragments = $this->collectWithInheritance($request->subject, $request, 0);

        if ($request->keys !== []) {
            $fragments = array_values(array_filter(
                $fragments,
                static fn (MetadataFragment $fragment): bool => in_array($fragment->key, $request->keys, true),
            ));
        }

        foreach ($fragments as $index => $fragment) {
            $schema = $this->schemas->get($fragment->key);
            $value = $this->normalizer->normalizeValue($fragment->value, $schema);
            $fragments[$index] = new MetadataFragment(
                key: $fragment->key,
                value: $value,
                origin: $fragment->origin,
                priority: $fragment->priority,
                final: $fragment->final,
            );
        }

        $items = $this->merger->merge($fragments, fn(string $key) => $this->schemas->get($key));

        if ($request->keys !== []) {
            foreach ($request->keys as $key) {
                if (array_key_exists($key, $items)) {
                    continue;
                }

                $schema = $this->schemas->get($key);

                if ($schema !== null) {
                    $items[$key] = $schema->defaultValue;
                }
            }
        }

        $bag = new MetadataBag($items);
        $this->cache[$cacheKey] = $bag;

        return $bag;
    }

    /**
     * @return array<int, MetadataFragment>
     */
    private function collectWithInheritance(MetadataSubjectInterface $subject, MetadataRequest $request, int $depth): array
    {
        $fragments = [];

        $parent = $subject->parent();

        if ($parent !== null) {
            $fragments = $this->collectWithInheritance($parent, $request, $depth + 1);
        }

        $current = $this->pipeline->collect(new MetadataRequest(
            subject: $subject,
            keys: $request->keys,
            scopes: $request->scopes,
            mode: $request->mode,
        ));

        if ($depth > 0) {
            foreach ($fragments as $i => $fragment) {
                $schema = $this->schemas->get($fragment->key);

                if ($schema !== null && ! $schema->inheritable) {
                    unset($fragments[$i]);
                    continue;
                }

                $fragments[$i] = new MetadataFragment(
                    key: $fragment->key,
                    value: $fragment->value,
                    origin: $fragment->origin,
                    priority: $fragment->priority - 1,
                    final: $fragment->final,
                );
            }

            $fragments = array_values($fragments);
        }

        return [...$fragments, ...$current];
    }

    private function cacheKey(MetadataRequest $request): string
    {
        $subject = $request->subject;

        return implode('|', [
            $subject->type()->value,
            $subject->id(),
            $request->mode->value,
            implode(',', $request->keys),
            implode(',', $request->scopes),
        ]);
    }
}
