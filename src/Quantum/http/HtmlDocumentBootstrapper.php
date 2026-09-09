<?php

declare(strict_types=1);

namespace Quantum\Http;

final class HtmlDocumentBootstrapper
{
    private const DEFAULT_DOCUMENT_CONTRACT = 'spa';
    private const RELOAD_DOCUMENT_CONTRACT = 'reload';
    private const DEFAULT_NAVIGATION_MODE = 'auto';
    private const RUNTIME_MARKER = 'data-volt-runtime="true"';
    private const DOCUMENT_ATTRIBUTE_NAMES = [
        'data-volt-document',
        'volt-document',
        'volt:document',
    ];
    private const LAYOUT_ATTRIBUTE_NAMES = [
        'data-volt-layout',
        'volt-layout',
        'volt:layout',
    ];
    private const NAVIGATION_MODE_ATTRIBUTE_NAMES = [
        'data-volt-navigation-mode',
        'volt-navigation-mode',
        'volt:navigation-mode',
    ];
    private const NAVIGATION_MODE_META_NAMES = [
        'volt-navigation-mode',
        'volt:navigation-mode',
    ];
    private const DOCUMENT_META_NAMES = [
        'volt-document',
        'volt:document',
    ];
    private const PAGE_TRANSITION_ATTRIBUTE_NAMES = [
        'data-volt-page-transition',
        'volt-page-transition',
        'volt:page-transition',
    ];
    private const PAGE_TRANSITION_PROFILE_ATTRIBUTE_NAMES = [
        'data-volt-page-transition-profile',
        'volt-page-transition-profile',
        'volt:page-transition-profile',
    ];
    private const PAGE_TRANSITION_DURATION_ATTRIBUTE_NAMES = [
        'data-volt-page-transition-duration',
        'volt-page-transition-duration',
        'volt:page-transition-duration',
    ];
    private const PAGE_TRANSITION_MODE_ATTRIBUTE_NAMES = [
        'data-volt-page-transition-mode',
        'volt-page-transition-mode',
        'volt:page-transition-mode',
    ];
    private const PAGE_TRANSITION_META_NAMES = [
        'volt-page-transition',
        'volt:page-transition',
    ];
    private const PAGE_TRANSITION_PROFILE_META_NAMES = [
        'volt-page-transition-profile',
        'volt:page-transition-profile',
    ];
    private const PAGE_TRANSITION_DURATION_META_NAMES = [
        'volt-page-transition-duration',
        'volt:page-transition-duration',
    ];
    private const PAGE_TRANSITION_MODE_META_NAMES = [
        'volt-page-transition-mode',
        'volt:page-transition-mode',
    ];
    private const HYDRATE_ATTRIBUTE_NAMES = [
        'data-volt-hydrate',
        'volt-hydrate',
        'volt:hydrate',
    ];
    private const HYDRATE_STRATEGY_ATTRIBUTE_NAMES = [
        'data-volt-hydrate-strategy',
        'volt-hydrate-strategy',
        'volt:hydrate-strategy',
    ];
    private const HYDRATE_DIRTY_STATE_ATTRIBUTE_NAMES = [
        'data-volt-hydrate-dirty-state',
        'volt-hydrate-dirty-state',
        'volt:hydrate-dirty-state',
    ];
    private const HYDRATE_META_NAMES = [
        'volt-hydrate',
        'volt:hydrate',
    ];
    private const HYDRATE_STRATEGY_META_NAMES = [
        'volt-hydrate-strategy',
        'volt:hydrate-strategy',
    ];
    private const HYDRATE_DIRTY_STATE_META_NAMES = [
        'volt-hydrate-dirty-state',
        'volt:hydrate-dirty-state',
    ];
    private const HEAD_MANAGED_KEY_ATTRIBUTE = 'data-volt-head-key';

    public function shouldBootstrap(Request $request, Response $response): bool
    {
        if (! $request->isConventionalHttpRequest()) {
            return false;
        }

        if ($this->isEmbeddableComponentRoute($request)) {
            return false;
        }

        if ($response instanceof JsonResponse || $response instanceof RedirectResponse) {
            return false;
        }

        if ($this->isAttachment($response)) {
            return false;
        }

        if ($this->hasNonHtmlContentType($response)) {
            return false;
        }

        $content = $response->content();

        if (trim($content) === '' || $this->hasRuntime($content)) {
            return false;
        }

        return $this->looksLikeHtml($content);
    }

    private function isEmbeddableComponentRoute(Request $request): bool
    {
        $screen = $request->routeMeta('screen');

        if (! is_array($screen)) {
            return false;
        }

        $kind = $screen['kind'] ?? null;
        $mode = $screen['mode'] ?? null;

        return is_string($kind)
            && strtolower(trim($kind)) === 'component'
            && is_string($mode)
            && strtolower(trim($mode)) === 'embeddable';
    }

    public function bootstrap(Request $request, Response $response): Response
    {
        $content = $this->decorateDocument($request, $response->content());
        $script = volt_runtime_script();

        $bodyOffset = stripos($content, '</body>');
        $htmlOffset = stripos($content, '</html>');

        if ($bodyOffset !== false) {
            $content = substr($content, 0, $bodyOffset) . $script . "\n" . substr($content, $bodyOffset);
        } elseif ($htmlOffset !== false) {
            $content = substr($content, 0, $htmlOffset) . $script . "\n" . substr($content, $htmlOffset);
        } else {
            $content = rtrim($content) . "\n" . $script;
        }

        $response->setContent($content);

        if (! $this->hasContentType($response)) {
            $response->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return $response;
    }

    private function decorateDocument(Request $request, string $content): string
    {
        if (stripos($content, '<body') === false) {
            return $content;
        }

        $documentContract = $this->resolvedDocumentContract($request, $content);

        $content = $this->ensureBodyAttribute(
            $content,
            self::DOCUMENT_ATTRIBUTE_NAMES,
            $documentContract,
        );

        $navigationMode = $this->resolvedNavigationMode($request, $content, $documentContract);

        if ($navigationMode !== null) {
            $content = $this->ensureBodyAttribute($content, self::NAVIGATION_MODE_ATTRIBUTE_NAMES, $navigationMode);
        }

        $content = $this->decorateLayoutAttributes($request, $content);
        $content = $this->decoratePageTransitionAttributes($request, $content);
        $content = $this->decorateHydrationAttributes($request, $content);

        return $this->decorateManagedHeadKeys($content);
    }

    private function hasRuntime(string $content): bool
    {
        return str_contains($content, self::RUNTIME_MARKER);
    }

    private function looksLikeHtml(string $content): bool
    {
        $trimmed = ltrim($content);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(<!DOCTYPE\s+html|<html\b|<body\b|<[a-zA-Z][^>]*>)/i', $trimmed) === 1) {
            return true;
        }

        return str_contains($content, 'data-volt-root="true"')
            || str_contains($content, 'volt:navigate')
            || str_contains($content, 'volt-navigate');
    }

    /**
     * @param array<int, string> $attributeNames
     */
    private function ensureBodyAttribute(string $content, array $attributeNames, string $value): string
    {
        return (string) preg_replace_callback('/<body\b[^>]*>/i', function (array $matches) use ($attributeNames, $value): string {
            $tag = $matches[0];

            foreach ($attributeNames as $attributeName) {
                if (preg_match('/\b' . preg_quote($attributeName, '/') . '\s*=/i', $tag) === 1) {
                    return $tag;
                }
            }

            return substr($tag, 0, -1) . ' ' . $attributeNames[0] . '="' . $value . '">';
        }, $content, 1);
    }

    private function decorateManagedHeadKeys(string $content): string
    {
        if (stripos($content, '<head') === false) {
            return $content;
        }

        return (string) preg_replace_callback('/(<head\b[^>]*>)(.*?)(<\/head>)/is', function (array $matches): string {
            $headContents = $matches[2] ?? '';
            $headContents = $this->decorateInlineHeadNodes($headContents, 'script');
            $headContents = $this->decorateInlineHeadNodes($headContents, 'style');

            return ($matches[1] ?? '<head>') . $headContents . ($matches[3] ?? '</head>');
        }, $content, 1);
    }

    private function decorateInlineHeadNodes(string $content, string $tag): string
    {
        $pattern = sprintf(
            '/<%1$s\b(?P<attributes>[^>]*)>(?P<content>.*?)<\/%1$s>/is',
            preg_quote($tag, '/'),
        );

        return (string) preg_replace_callback($pattern, function (array $matches) use ($tag): string {
            $attributes = $matches['attributes'] ?? '';
            $nodeContent = $matches['content'] ?? '';

            if ($this->inlineHeadNodeAlreadyManaged($tag, $attributes)) {
                return $matches[0];
            }

            $managedKey = $this->managedHeadKeyForInlineNode($tag, $attributes, $nodeContent);

            return sprintf(
                '<%s%s %s="%s">%s</%s>',
                $tag,
                $attributes,
                self::HEAD_MANAGED_KEY_ATTRIBUTE,
                $managedKey,
                $nodeContent,
                $tag,
            );
        }, $content);
    }

    private function inlineHeadNodeAlreadyManaged(string $tag, string $attributes): bool
    {
        if (preg_match('/\b' . preg_quote(self::HEAD_MANAGED_KEY_ATTRIBUTE, '/') . '\s*=/i', $attributes) === 1) {
            return true;
        }

        return match ($tag) {
            'script' => preg_match('/\bsrc\s*=/i', $attributes) === 1,
            'style' => preg_match('/\bid\s*=/i', $attributes) === 1,
            default => true,
        };
    }

    private function managedHeadKeyForInlineNode(string $tag, string $attributes, string $content): string
    {
        return sprintf(
            'auto-%s:%s',
            $tag,
            sha1($tag . '|' . $this->normalizedHeadNodeAttributes($attributes) . '|' . $content),
        );
    }

    private function normalizedHeadNodeAttributes(string $attributes): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $attributes));
    }

    private function declaresNavigationModeMeta(string $content): bool
    {
        return $this->declaredMetaContent($content, self::NAVIGATION_MODE_META_NAMES) !== null;
    }

    private function declaredDocumentContract(string $content): ?string
    {
        $value = $this->declaredMetaContent($content, self::DOCUMENT_META_NAMES);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeDocumentContract($value);
    }

    private function bodyDocumentContract(string $content): ?string
    {
        $value = $this->bodyAttributeValue($content, self::DOCUMENT_ATTRIBUTE_NAMES);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->normalizeDocumentContract($value);
    }

    private function normalizeDocumentContract(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'reload', 'reload-only', 'static', 'non-spa', 'document' => self::RELOAD_DOCUMENT_CONTRACT,
            default => self::DEFAULT_DOCUMENT_CONTRACT,
        };
    }

    private function resolvedDocumentContract(Request $request, string $content): string
    {
        return $this->declaredDocumentContract($content)
            ?? $this->bodyDocumentContract($content)
            ?? $this->runtimeDocumentContract($request)
            ?? self::DEFAULT_DOCUMENT_CONTRACT;
    }

    private function resolvedNavigationMode(Request $request, string $content, string $documentContract): ?string
    {
        if ($this->declaresNavigationModeMeta($content) || $this->bodyAttributeValue($content, self::NAVIGATION_MODE_ATTRIBUTE_NAMES) !== null) {
            return null;
        }

        $navigationMode = $this->runtimeNavigationMode($request);

        if ($navigationMode !== null) {
            return $navigationMode;
        }

        return $documentContract !== self::RELOAD_DOCUMENT_CONTRACT
            ? self::DEFAULT_NAVIGATION_MODE
            : null;
    }

    private function decoratePageTransitionAttributes(Request $request, string $content): string
    {
        $transition = $this->runtimePageTransition($request);

        if ($transition === []) {
            return $content;
        }

        $projections = [
            [self::PAGE_TRANSITION_ATTRIBUTE_NAMES, self::PAGE_TRANSITION_META_NAMES, 'name'],
            [self::PAGE_TRANSITION_PROFILE_ATTRIBUTE_NAMES, self::PAGE_TRANSITION_PROFILE_META_NAMES, 'profile'],
            [self::PAGE_TRANSITION_DURATION_ATTRIBUTE_NAMES, self::PAGE_TRANSITION_DURATION_META_NAMES, 'duration'],
            [self::PAGE_TRANSITION_MODE_ATTRIBUTE_NAMES, self::PAGE_TRANSITION_MODE_META_NAMES, 'mode'],
        ];

        foreach ($projections as [$attributeNames, $metaNames, $key]) {
            $value = $transition[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (
                $this->declaredMetaContent($content, $metaNames) !== null
                || $this->bodyAttributeValue($content, $attributeNames) !== null
            ) {
                continue;
            }

            $content = $this->ensureBodyAttribute($content, $attributeNames, trim($value));
        }

        return $content;
    }

    private function decorateLayoutAttributes(Request $request, string $content): string
    {
        if ($this->bodyAttributeValue($content, self::LAYOUT_ATTRIBUTE_NAMES) !== null) {
            return $content;
        }

        $layout = $request->routeRuntimeMeta('layout');

        if (! is_string($layout) || trim($layout) === '') {
            return $content;
        }

        return $this->ensureBodyAttribute($content, self::LAYOUT_ATTRIBUTE_NAMES, trim($layout));
    }

    private function decorateHydrationAttributes(Request $request, string $content): string
    {
        $hydration = $this->runtimeHydration($request);

        if ($hydration === []) {
            return $content;
        }

        $projections = [
            [self::HYDRATE_ATTRIBUTE_NAMES, self::HYDRATE_META_NAMES, 'enabled'],
            [self::HYDRATE_STRATEGY_ATTRIBUTE_NAMES, self::HYDRATE_STRATEGY_META_NAMES, 'strategy'],
            [self::HYDRATE_DIRTY_STATE_ATTRIBUTE_NAMES, self::HYDRATE_DIRTY_STATE_META_NAMES, 'dirtyState'],
        ];

        foreach ($projections as [$attributeNames, $metaNames, $key]) {
            $value = $hydration[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (
                $this->declaredMetaContent($content, $metaNames) !== null
                || $this->bodyAttributeValue($content, $attributeNames) !== null
            ) {
                continue;
            }

            $content = $this->ensureBodyAttribute($content, $attributeNames, trim($value));
        }

        return $content;
    }

    /**
     * @return array{name?: string, profile?: string, duration?: string, mode?: string}
     */
    private function runtimePageTransition(Request $request): array
    {
        $transition = $request->routeRuntimeMeta('transition');

        if (! is_array($transition)) {
            $transition = $request->routeRuntimeMeta('pageTransition');
        }

        if (is_string($transition) && trim($transition) !== '') {
            return ['name' => trim($transition)];
        }

        if (! is_array($transition)) {
            return [];
        }

        $normalized = [];

        foreach (
            [
                'name' => ['name', 'transition'],
                'profile' => ['profile'],
                'duration' => ['duration'],
                'mode' => ['mode'],
            ] as $target => $candidates
        ) {
            foreach ($candidates as $candidate) {
                $value = $transition[$candidate] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $normalized[$target] = (string) $value;
                    break;
                }

                if (is_string($value) && trim($value) !== '') {
                    $normalized[$target] = trim($value);
                    break;
                }
            }
        }

        $fallbackMap = [
            'profile' => ['pageTransitionProfile', 'transitionProfile'],
            'duration' => ['pageTransitionDuration', 'transitionDuration'],
            'mode' => ['pageTransitionMode', 'transitionMode'],
        ];

        foreach ($fallbackMap as $target => $keys) {
            if (isset($normalized[$target])) {
                continue;
            }

            foreach ($keys as $key) {
                $value = $request->routeRuntimeMeta($key);

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $normalized[$target] = (string) $value;
                    break;
                }

                if (is_string($value) && trim($value) !== '') {
                    $normalized[$target] = trim($value);
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array{enabled?: string, strategy?: string, dirtyState?: string}
     */
    private function runtimeHydration(Request $request): array
    {
        $hydrate = $request->routeRuntimeMeta('hydrate');

        if (is_bool($hydrate)) {
            return ['enabled' => $hydrate ? 'true' : 'false'];
        }

        if (is_string($hydrate) && trim($hydrate) !== '') {
            $normalized = strtolower(trim($hydrate));

            if (in_array($normalized, ['true', 'false', 'on', 'off', 'enabled', 'disabled'], true)) {
                return [
                    'enabled' => in_array($normalized, ['true', 'on', 'enabled'], true) ? 'true' : 'false',
                ];
            }

            return [
                'enabled' => 'true',
                'strategy' => trim($hydrate),
            ];
        }

        if (! is_array($hydrate)) {
            return $this->runtimeHydrationFallback($request);
        }

        $normalized = [];
        $enabled = $hydrate['enabled'] ?? null;

        if (is_bool($enabled)) {
            $normalized['enabled'] = $enabled ? 'true' : 'false';
        } elseif (is_string($enabled) && trim($enabled) !== '') {
            $normalized['enabled'] = strtolower(trim($enabled));
        }

        foreach (
            [
                'strategy' => ['strategy'],
                'dirtyState' => ['dirtyState', 'dirty'],
            ] as $target => $candidates
        ) {
            foreach ($candidates as $candidate) {
                $value = $hydrate[$candidate] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $normalized[$target] = trim($value);
                break;
            }
        }

        if (! isset($normalized['enabled']) && isset($normalized['strategy'])) {
            $normalized['enabled'] = 'true';
        }

        return $normalized !== [] ? $normalized : $this->runtimeHydrationFallback($request);
    }

    /**
     * @return array{enabled?: string, strategy?: string, dirtyState?: string}
     */
    private function runtimeHydrationFallback(Request $request): array
    {
        $normalized = [];

        foreach (
            [
                'strategy' => ['hydrationStrategy', 'hydrateStrategy'],
                'dirtyState' => ['hydrationDirtyState', 'hydrateDirtyState'],
            ] as $target => $keys
        ) {
            foreach ($keys as $key) {
                $value = $request->routeRuntimeMeta($key);

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $normalized[$target] = trim($value);
                break;
            }
        }

        $enabled = $request->routeRuntimeMeta('hydrationEnabled');

        if (is_bool($enabled)) {
            $normalized['enabled'] = $enabled ? 'true' : 'false';
        } elseif (! isset($normalized['enabled']) && $normalized !== []) {
            $normalized['enabled'] = 'true';
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $metaNames
     */
    private function declaredMetaContent(string $content, array $metaNames): ?string
    {
        foreach ($metaNames as $metaName) {
            if (preg_match('/<meta\b[^>]*name\s*=\s*["\']' . preg_quote($metaName, '/') . '["\'][^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }

            if (preg_match('/<meta\b[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*name\s*=\s*["\']' . preg_quote($metaName, '/') . '["\'][^>]*>/i', $content, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $attributeNames
     */
    private function bodyAttributeValue(string $content, array $attributeNames): ?string
    {
        foreach ($attributeNames as $attributeName) {
            if (preg_match('/<body\b[^>]*\b' . preg_quote($attributeName, '/') . '\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        return null;
    }

    private function runtimeDocumentContract(Request $request): ?string
    {
        $document = $request->routeRuntimeMeta('document');

        if (! is_string($document) || trim($document) === '') {
            $document = $request->routeRuntimeMeta('contract');
        }

        if (! is_string($document) || trim($document) === '') {
            $mode = $request->routeRuntimeMeta('mode');

            if (is_string($mode) && $this->supportsRuntimeDocumentMode($mode)) {
                $document = $mode;
            }
        }

        if (! is_string($document) || trim($document) === '') {
            return null;
        }

        return $this->normalizeDocumentContract($document);
    }

    private function runtimeNavigationMode(Request $request): ?string
    {
        $navigationMode = $request->routeRuntimeMeta('navigation');

        if (! is_string($navigationMode) || trim($navigationMode) === '') {
            $navigationMode = $request->routeRuntimeMeta('navigationMode');
        }

        if (! is_string($navigationMode) || trim($navigationMode) === '') {
            return null;
        }

        return strtolower(trim($navigationMode));
    }

    private function supportsRuntimeDocumentMode(string $mode): bool
    {
        return in_array(strtolower(trim($mode)), [
            self::DEFAULT_DOCUMENT_CONTRACT,
            self::RELOAD_DOCUMENT_CONTRACT,
            'reload-only',
            'static',
            'non-spa',
            'document',
        ], true);
    }

    private function hasContentType(Response $response): bool
    {
        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'Content-Type') === 0 && is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasNonHtmlContentType(Response $response): bool
    {
        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'Content-Type') !== 0) {
                continue;
            }

            if (! is_string($value)) {
                return false;
            }

            $normalized = strtoupper(trim($value));

            if ($normalized === '') {
                return false;
            }

            return ! str_contains($normalized, 'TEXT/HTML');
        }

        return false;
    }

    private function isAttachment(Response $response): bool
    {
        foreach ($response->headers() as $name => $value) {
            if (strcasecmp($name, 'Content-Disposition') !== 0) {
                continue;
            }

            if (! is_string($value)) {
                return false;
            }

            return str_contains(strtolower($value), 'attachment');
        }

        return false;
    }
}
