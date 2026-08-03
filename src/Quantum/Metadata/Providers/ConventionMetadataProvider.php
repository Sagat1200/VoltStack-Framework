<?php

declare(strict_types=1);

namespace Quantum\Metadata\Providers;

use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\Contracts\MetadataSubjectInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\RouteMatchSubject;

final class ConventionMetadataProvider implements MetadataProviderInterface
{
    public function name(): string
    {
        return 'convention';
    }

    public function priority(): int
    {
        return 500;
    }

    public function supports(MetadataRequest $request): bool
    {
        return $request->subject instanceof ControllerClassSubject;
    }

    public function provide(MetadataRequest $request): array
    {
        $subject = $request->subject;

        if (! $subject instanceof ControllerClassSubject) {
            return [];
        }

        $controllerClass = $subject->controllerClass();
        $origin = new MetadataOrigin(
            provider: $this->name(),
            type: 'convention',
            location: $controllerClass,
        );

        $fragments = [];

        if (str_starts_with($controllerClass, 'App\\Controllers\\Admin\\')) {
            $fragments[] = new MetadataFragment(
                key: 'controller.interceptors',
                value: ['auth'],
                origin: $origin,
                priority: $this->priority(),
            );
        }

        $routeSubject = $this->findRouteSubject($subject);

        if ($routeSubject !== null) {
            $uri = $routeSubject->match()->route()->uri();

            if (str_starts_with($uri, '/api')) {
                $fragments[] = new MetadataFragment(
                    key: 'controller.interceptors',
                    value: ['throttle:api'],
                    origin: $origin,
                    priority: $this->priority(),
                );
            }
        }

        return $fragments;
    }

    private function findRouteSubject(MetadataSubjectInterface $subject): ?RouteMatchSubject
    {
        $current = $subject;

        while (true) {
            if ($current instanceof RouteMatchSubject) {
                return $current;
            }

            $parent = $current->parent();

            if ($parent === null) {
                return null;
            }

            $current = $parent;
        }
    }
}
