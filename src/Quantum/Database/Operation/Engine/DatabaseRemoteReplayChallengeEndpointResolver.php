<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

final class DatabaseRemoteReplayChallengeEndpointResolver
{
    /**
     * @param array<string, string> $endpointMap
     * @param list<string> $knownNodes
     * @param list<string> $trustedNodes
     * @param null|\Closure(): array<string, array<string, mixed>> $advertisedEndpointProvider
     * @param null|\Closure(): \DateTimeImmutable $clock
     */
    public function __construct(
        private readonly array $endpointMap,
        private readonly ?string $endpointTemplate = null,
        private readonly ?string $defaultPath = null,
        private readonly array $knownNodes = [],
        private readonly array $trustedNodes = [],
        private readonly string $healthDiscoveryMode = 'allow',
        private readonly int $healthAdvertisementMaxAgeSeconds = 300,
        private readonly ?\Closure $advertisedEndpointProvider = null,
        private readonly ?\Closure $clock = null,
    ) {}

    public function resolve(?string $nodeId): DatabaseRemoteReplayChallengeEndpointResolution
    {
        $normalizedNodeId = is_string($nodeId) ? trim($nodeId) : '';
        if ($normalizedNodeId === '') {
            return new DatabaseRemoteReplayChallengeEndpointResolution(
                status: 'missing_node_id',
                details: ['reason' => 'missing_node_id'],
            );
        }

        $mappedEndpoint = trim((string) ($this->endpointMap[$normalizedNodeId] ?? ''));
        if ($mappedEndpoint !== '') {
            return new DatabaseRemoteReplayChallengeEndpointResolution(
                status: 'resolved',
                nodeId: $normalizedNodeId,
                endpoint: $mappedEndpoint,
                strategy: 'endpoint_map',
            );
        }

        $advertised = $this->advertisedEndpoints();
        $advertisedEndpoint = trim((string) (($advertised[$normalizedNodeId]['endpoint'] ?? null) ?: ''));
        if ($advertisedEndpoint !== '') {
            $advertisementDetails = $this->healthAdvertisementDetails($normalizedNodeId, $advertised[$normalizedNodeId]);
            $blockedStatus = $this->blockedHealthAdvertisementStatus($advertisementDetails);

            if ($blockedStatus !== null) {
                return new DatabaseRemoteReplayChallengeEndpointResolution(
                    status: $blockedStatus,
                    nodeId: $normalizedNodeId,
                    endpoint: $advertisedEndpoint,
                    strategy: 'health_advertisement',
                    details: $advertisementDetails,
                );
            }

            return new DatabaseRemoteReplayChallengeEndpointResolution(
                status: 'resolved',
                nodeId: $normalizedNodeId,
                endpoint: $advertisedEndpoint,
                strategy: 'health_advertisement',
                details: $advertisementDetails,
            );
        }

        $template = trim((string) ($this->endpointTemplate ?? ''));
        if ($template !== '') {
            $path = trim((string) ($this->defaultPath ?? ''));
            $endpoint = strtr($template, [
                '{node_id}' => rawurlencode($normalizedNodeId),
                '{path}' => $path,
            ]);
            $endpoint = trim($endpoint);

            if ($endpoint !== '' && ! str_contains($endpoint, '{node_id}') && ! str_contains($endpoint, '{path}')) {
                return new DatabaseRemoteReplayChallengeEndpointResolution(
                    status: 'resolved',
                    nodeId: $normalizedNodeId,
                    endpoint: $endpoint,
                    strategy: 'endpoint_template',
                    details: [
                        'template' => $template,
                        'path' => $path !== '' ? $path : null,
                    ],
                );
            }
        }

        return new DatabaseRemoteReplayChallengeEndpointResolution(
            status: 'unconfigured',
            nodeId: $normalizedNodeId,
            strategy: $template !== '' ? 'endpoint_template' : 'none',
            details: [
                'reason' => $template !== '' ? 'template_not_expandable' : 'no_endpoint_configuration',
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function knownNodes(): array
    {
        $nodes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            array_merge(array_keys($this->endpointMap), $this->knownNodes, array_keys($this->advertisedEndpoints())),
        ))));
        sort($nodes);

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(?string $currentNodeId = null): array
    {
        $knownNodes = $this->knownNodes();
        $resolutions = array_map(
            fn(string $nodeId): array => $this->resolve($nodeId)->toArray(),
            $knownNodes,
        );
        $resolvedNodes = array_values(array_map(
            static fn(array $resolution): string => (string) ($resolution['node_id'] ?? ''),
            array_filter($resolutions, static fn(array $resolution): bool => ($resolution['status'] ?? null) === 'resolved')
        ));

        return [
            'current_node_id' => $currentNodeId,
            'default_path' => $this->defaultPath,
            'endpoint_template' => $this->endpointTemplate,
            'health_discovery_mode' => $this->normalizedHealthDiscoveryMode(),
            'health_advertisement_max_age_seconds' => $this->healthAdvertisementMaxAgeSeconds,
            'advertised_nodes' => array_values(array_keys($this->advertisedEndpoints())),
            'known_nodes' => $knownNodes,
            'configured_nodes' => count($knownNodes),
            'resolved_nodes' => $resolvedNodes,
            'resolved_count' => count($resolvedNodes),
            'resolutions' => $resolutions,
        ];
    }

    /**
     * @param array<string, mixed> $advertisement
     * @return array<string, mixed>
     */
    private function healthAdvertisementDetails(string $nodeId, array $advertisement): array
    {
        $details = array_filter([
            'protocol' => $advertisement['protocol'] ?? null,
            'supported_protocols' => is_array($advertisement['supported_protocols'] ?? null)
                ? $advertisement['supported_protocols']
                : null,
            'capabilities' => is_array($advertisement['capabilities'] ?? null)
                ? $advertisement['capabilities']
                : null,
            'key_id' => $advertisement['key_id'] ?? null,
            'source' => $advertisement['source'] ?? null,
            'path' => $advertisement['path'] ?? null,
            'transport' => $advertisement['transport'] ?? null,
            'advertised_generated_at' => $advertisement['generated_at'] ?? null,
            'advertised_report_node_id' => $advertisement['report_node_id'] ?? null,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);

        [$freshnessStatus, $ageSeconds] = $this->evaluateHealthAdvertisementFreshness($advertisement);
        $details['advertisement_freshness'] = $freshnessStatus;
        $details['advertisement_age_seconds'] = $ageSeconds;

        $trustStatus = $this->evaluateHealthAdvertisementTrust($nodeId);
        $details['advertisement_trust'] = $trustStatus;
        $details['health_discovery_mode'] = $this->normalizedHealthDiscoveryMode();

        return $details;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function blockedHealthAdvertisementStatus(array $details): ?string
    {
        $mode = $this->normalizedHealthDiscoveryMode();
        if ($mode !== 'require') {
            return null;
        }

        $freshness = trim((string) ($details['advertisement_freshness'] ?? ''));
        if ($freshness === 'stale') {
            return 'stale_advertisement';
        }
        if ($freshness === 'unknown') {
            return 'unknown_advertisement_age';
        }

        $trust = trim((string) ($details['advertisement_trust'] ?? ''));
        if ($trust === 'untrusted') {
            return 'untrusted_advertisement';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $advertisement
     * @return array{0:string,1:int|null}
     */
    private function evaluateHealthAdvertisementFreshness(array $advertisement): array
    {
        if ($this->healthAdvertisementMaxAgeSeconds <= 0) {
            return ['not_checked', null];
        }

        $generatedAt = trim((string) ($advertisement['generated_at'] ?? ''));
        if ($generatedAt === '') {
            return ['unknown', null];
        }

        try {
            $generated = new \DateTimeImmutable($generatedAt);
        } catch (\Throwable) {
            return ['unknown', null];
        }

        $now = $this->now();
        $age = max(0, $now->getTimestamp() - $generated->getTimestamp());

        return $age <= $this->healthAdvertisementMaxAgeSeconds
            ? ['fresh', $age]
            : ['stale', $age];
    }

    private function evaluateHealthAdvertisementTrust(string $nodeId): string
    {
        $trustedNodes = array_values(array_filter(array_map(
            static fn(mixed $value): string => is_string($value) ? trim($value) : '',
            $this->trustedNodes,
        )));

        if ($trustedNodes === []) {
            return 'not_configured';
        }

        return in_array($nodeId, $trustedNodes, true)
            ? 'trusted'
            : 'untrusted';
    }

    private function normalizedHealthDiscoveryMode(): string
    {
        $mode = strtolower(trim($this->healthDiscoveryMode));

        return in_array($mode, ['allow', 'warn', 'require'], true)
            ? $mode
            : 'allow';
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock instanceof \Closure) {
            $current = ($this->clock)();
            if ($current instanceof \DateTimeImmutable) {
                return $current;
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function advertisedEndpoints(): array
    {
        if (!$this->advertisedEndpointProvider instanceof \Closure) {
            return [];
        }

        $advertised = ($this->advertisedEndpointProvider)();
        if (!is_array($advertised)) {
            return [];
        }

        $normalized = [];
        foreach ($advertised as $nodeId => $details) {
            $normalizedNodeId = trim((string) $nodeId);
            if ($normalizedNodeId === '' || !is_array($details)) {
                continue;
            }

            $endpoint = trim((string) ($details['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }

            $normalized[$normalizedNodeId] = $details;
        }

        return $normalized;
    }
}
