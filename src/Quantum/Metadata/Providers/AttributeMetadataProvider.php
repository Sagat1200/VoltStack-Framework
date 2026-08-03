<?php

declare(strict_types=1);

namespace Quantum\Metadata\Providers;

use Quantum\Controllers\Attributes\Interceptors;
use Quantum\Controllers\Attributes\ParameterAliases;
use Quantum\Metadata\Attributes\Meta;
use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\ControllerMethodSubject;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

final class AttributeMetadataProvider implements MetadataProviderInterface
{
    public function name(): string
    {
        return 'attributes';
    }

    public function priority(): int
    {
        return 750;
    }

    public function supports(MetadataRequest $request): bool
    {
        return $request->subject instanceof ControllerClassSubject
            || $request->subject instanceof ControllerMethodSubject;
    }

    public function provide(MetadataRequest $request): array
    {
        $subject = $request->subject;

        if ($subject instanceof ControllerClassSubject) {
            return $this->fromReflectionClass($subject->controllerClass());
        }

        if ($subject instanceof ControllerMethodSubject) {
            return $this->fromReflectionMethod($subject->controllerClass(), $subject->method());
        }

        return [];
    }

    private function fromReflectionClass(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);

        return $this->buildFragments(
            $this->collectAttributes($reflection),
            $class,
        );
    }

    private function fromReflectionMethod(string $class, string $method): array
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        $reflection = new ReflectionMethod($class, $method);

        return $this->buildFragments(
            $this->collectAttributes($reflection),
            $class . '@' . $method,
        );
    }

    private function collectAttributes(ReflectionClass|ReflectionMethod $reflection): array
    {
        return [
            ...$reflection->getAttributes(Meta::class, ReflectionAttribute::IS_INSTANCEOF),
            ...$reflection->getAttributes(Interceptors::class, ReflectionAttribute::IS_INSTANCEOF),
            ...$reflection->getAttributes(ParameterAliases::class, ReflectionAttribute::IS_INSTANCEOF),
        ];
    }

    private function buildFragments(array $attributes, string $location): array
    {
        $fragments = [];

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            if (! $instance instanceof Meta) {
                if ($instance instanceof Interceptors) {
                    $fragments[] = new MetadataFragment(
                        key: 'controller.interceptors',
                        value: array_values($instance->interceptors),
                        origin: new MetadataOrigin(
                            provider: $this->name(),
                            type: 'attribute',
                            location: $location,
                        ),
                        priority: $instance->priority ?? $this->priority(),
                        final: $instance->final,
                    );
                }

                if ($instance instanceof ParameterAliases) {
                    $fragments[] = new MetadataFragment(
                        key: 'parameter_aliases',
                        value: $instance->aliases,
                        origin: new MetadataOrigin(
                            provider: $this->name(),
                            type: 'attribute',
                            location: $location,
                        ),
                        priority: $instance->priority ?? $this->priority(),
                        final: $instance->final,
                    );
                }

                continue;
            }

            $fragments[] = new MetadataFragment(
                key: $instance->key,
                value: $instance->value,
                origin: new MetadataOrigin(
                    provider: $this->name(),
                    type: 'attribute',
                    location: $location,
                ),
                priority: $instance->priority ?? $this->priority(),
                final: $instance->final,
            );
        }

        return $fragments;
    }
}