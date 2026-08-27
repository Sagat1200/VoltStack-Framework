<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

final class DatabaseRemoteReplayChallengeEndpointResolver
{
    /**
     * @param array<string, string> $endpointMap
     * @param list<string> $knownNodes
     */
    public function __construct(
        private readonly array $endpointMap,
        private readonly ?string $endpointTemplate = null,
        private readonly ?string $defaultPath = null,
        private readonly array $knownNodes = [],
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
            array_merge(array_keys($this->endpointMap), $this->knownNodes),
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
            'known_nodes' => $knownNodes,
            'configured_nodes' => count($knownNodes),
            'resolved_nodes' => $resolvedNodes,
            'resolved_count' => count($resolvedNodes),
            'resolutions' => $resolutions,
        ];
    }
}