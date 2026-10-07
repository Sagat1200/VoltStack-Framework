<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Mapping;

use Closure;
use Quantum\Exceptions\Catalog\SemanticCatalogEntry;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Model\FailureSnapshot;

final readonly class MappingRule
{
    /**
     * @param Closure(FailureSnapshot, ExceptionContext): bool|null $predicate
     * @param Closure(FailureSnapshot, ExceptionContext): array<string, scalar|array|null> $parameterFactory
     */
    public function __construct(
        public string $id,
        public string $exceptionType,
        public string $catalogCode,
        public int $priority = 0,
        public ?Closure $predicate = null,
        public ?Closure $parameterFactory = null,
        public ?string $serviceId = null,
        public ?string $originPackage = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('MappingRule id must not be empty.');
        }

        if (!class_exists($exceptionType) && !interface_exists($exceptionType)) {
            throw new \InvalidArgumentException(sprintf('MappingRule exceptionType [%s] is not available.', $exceptionType));
        }

        if ($catalogCode === '') {
            throw new \InvalidArgumentException('MappingRule catalogCode must not be empty.');
        }
    }

    public function appliesTo(string $className): ?MappingRuleMatch
    {
        if ($className === '') {
            return null;
        }

        if ($className === $this->exceptionType) {
            return new MappingRuleMatch($this, specificityRank: 0, distance: 0);
        }

        if (interface_exists($this->exceptionType) && in_array($this->exceptionType, class_implements($className) ?: [], true)) {
            return new MappingRuleMatch($this, specificityRank: 2, distance: 0);
        }

        if (is_subclass_of($className, $this->exceptionType, true)) {
            return new MappingRuleMatch($this, specificityRank: 1, distance: $this->ancestorDistance($className));
        }

        return null;
    }

    /**
     * @return array<string, scalar|array|null>
     */
    public function buildParameters(FailureSnapshot $failure, ExceptionContext $context, SemanticCatalogEntry $entry): array
    {
        $resolved = $this->parameterFactory !== null
            ? ($this->parameterFactory)($failure, $context)
            : [];

        $normalized = [];

        foreach ($entry->safeParameterKeys as $key) {
            if (array_key_exists($key, $resolved)) {
                $normalized[$key] = $resolved[$key];
            }
        }

        return $normalized;
    }

    public function predicateMatches(FailureSnapshot $failure, ExceptionContext $context): bool
    {
        if ($this->predicate === null) {
            return true;
        }

        return (bool) (($this->predicate)($failure, $context) ?? false);
    }

    private function ancestorDistance(string $className): int
    {
        $distance = 1;
        $parent = get_parent_class($className);

        while ($parent !== false) {
            if ($parent === $this->exceptionType) {
                return $distance;
            }

            $parent = get_parent_class($parent);
            $distance++;
        }

        return PHP_INT_MAX;
    }
}
