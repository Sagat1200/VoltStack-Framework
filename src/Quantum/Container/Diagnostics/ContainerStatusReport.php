<?php

declare(strict_types=1);

namespace Quantum\Container\Diagnostics;

use JsonSerializable;

final readonly class ContainerStatusReport implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $filteredIssues
     * @param array<string, mixed> $graph
     */
    public function __construct(
        public int $serviceCount,
        public int $aliasCount,
        public int $analyzableServiceCount,
        public int $analyzableConcreteClassCount,
        public int $closureBindingCount,
        public int $singletonCount,
        public int $scopedCount,
        public int $transientCount,
        public int $instanceBindingCount,
        public int $issueCount,
        public int $missingClassCount,
        public int $notInstantiableCount,
        public int $unresolvableParameterCount,
        public int $unresolvableDependencyCount,
        public int $dependencyCycleCount,
        public int $scopeCaptureViolationCount,
        public array $issues,
        public array $filteredIssues,
        public array $graph,
        public int $filteredIssueCount,
        public int $truncatedIssueCount = 0,
        public int $appliedIssueLimit = 0,
        public ?string $codeFilter = null,
        public ?string $serviceFilter = null,
        public ?string $severityFilter = null,
        public string $format = 'full',
    ) {
    }

    public function healthy(): bool
    {
        return $this->issueCount === 0;
    }

    public function healthyUnderFilter(): bool
    {
        return $this->filteredIssueCount === 0;
    }

    public function isFiltered(): bool
    {
        return $this->codeFilter !== null
            || $this->serviceFilter !== null
            || $this->severityFilter !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy(),
            'healthy_under_filter' => $this->healthyUnderFilter(),
            'format' => $this->format,
            'filters' => [
                'code' => $this->codeFilter,
                'service' => $this->serviceFilter,
                'severity' => $this->severityFilter,
            ],
            'service_count' => $this->serviceCount,
            'alias_count' => $this->aliasCount,
            'analyzable_service_count' => $this->analyzableServiceCount,
            'analyzable_concrete_class_count' => $this->analyzableConcreteClassCount,
            'closure_binding_count' => $this->closureBindingCount,
            'instance_binding_count' => $this->instanceBindingCount,
            'lifetime_breakdown' => [
                'singleton' => $this->singletonCount,
                'scoped' => $this->scopedCount,
                'transient' => $this->transientCount,
            ],
            'issue_count' => $this->issueCount,
            'issue_breakdown' => [
                'missing_class' => $this->missingClassCount,
                'not_instantiable' => $this->notInstantiableCount,
                'unresolvable_parameter' => $this->unresolvableParameterCount,
                'unresolvable_dependency' => $this->unresolvableDependencyCount,
                'dependency_cycle' => $this->dependencyCycleCount,
                'scope_capture_violation' => $this->scopeCaptureViolationCount,
            ],
            'filtered_issue_count' => $this->filteredIssueCount,
            'truncated_issue_count' => $this->truncatedIssueCount,
            'applied_issue_limit' => $this->appliedIssueLimit,
            'issues' => $this->format === 'brief' ? [] : $this->filteredIssues,
            'graph' => $this->format === 'graph' ? $this->graph : ($this->format === 'brief' ? [] : $this->graph),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
