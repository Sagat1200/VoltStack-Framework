<?php

declare(strict_types=1);

namespace Quantum\Metadata\Providers;

use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerMethodSubject;

final class ReflectionMetadataProvider implements MetadataProviderInterface
{
    public function name(): string
    {
        return 'reflection';
    }

    public function priority(): int
    {
        return 700;
    }

    public function supports(MetadataRequest $request): bool
    {
        return $request->subject instanceof ControllerMethodSubject;
    }

    public function provide(MetadataRequest $request): array
    {
        $subject = $request->subject;

        if (! $subject instanceof ControllerMethodSubject) {
            return [];
        }

        $origin = new MetadataOrigin(
            provider: $this->name(),
            type: 'reflection',
            location: $subject->id(),
        );

        return [
            new MetadataFragment(
                key: 'controller.reflection.class',
                value: $subject->controllerClass(),
                origin: $origin,
                priority: $this->priority(),
            ),
            new MetadataFragment(
                key: 'controller.reflection.method',
                value: $subject->method(),
                origin: $origin,
                priority: $this->priority(),
            ),
        ];
    }
}
