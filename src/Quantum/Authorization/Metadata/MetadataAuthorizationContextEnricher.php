<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\RouteMatch;

final readonly class MetadataAuthorizationContextEnricher implements AuthorizationRequestEnricherInterface
{
    public function __construct(
        private AuthorizationMetadataResolverInterface $metadata,
    ) {}

    public function enrich(AuthorizationRequest $request): AuthorizationRequest
    {
        $context = $request->context();
        $match = $context->attribute('route_match');

        if (! $match instanceof RouteMatch) {
            return $request;
        }

        $definition = $context->attribute('controller_definition');
        $definition = $definition instanceof ControllerDefinition ? $definition : null;

        $metadata = $this->metadata->resolve($match, $definition);
        $matched = array_values(array_filter(
            $metadata->requirements(),
            fn (array $requirement): bool => ($requirement['ability'] ?? null) === $request->ability()->name(),
        ));

        return $request->withContext($context->mergeAttributes([
            'authorization.metadata' => $metadata,
            'authorization.metadata.public' => $metadata->public(),
            'authorization.metadata.requirements' => $metadata->requirements(),
            'authorization.metadata.matched_requirements' => $matched,
        ]));
    }
}
