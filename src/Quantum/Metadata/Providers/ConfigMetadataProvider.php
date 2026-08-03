<?php

declare(strict_types=1);

namespace Quantum\Metadata\Providers;

use Quantum\Config\ConfigRepository;
use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\ControllerMethodSubject;
use VoltStack\Framework\Application;

final readonly class ConfigMetadataProvider implements MetadataProviderInterface
{
    public function __construct(private Application $app) {}

    public function name(): string
    {
        return 'config';
    }

    public function priority(): int
    {
        return 800;
    }

    public function supports(MetadataRequest $request): bool
    {
        return $request->subject instanceof ControllerClassSubject
            || $request->subject instanceof ControllerMethodSubject;
    }

    public function provide(MetadataRequest $request): array
    {
        $config = $this->app->make(ConfigRepository::class);

        $fragments = [];

        $lifecycle = $config->get('controller_lifecycle', []);

        if (is_array($lifecycle)) {
            $fragments = [
                ...$fragments,
                ...$this->flatten('controller.lifecycle', $lifecycle, 'config/controller_lifecycle.php'),
            ];
        }

        $compilation = $config->get('controller_compilation', []);

        if (is_array($compilation)) {
            $fragments = [
                ...$fragments,
                ...$this->flatten('controller.compilation', $compilation, 'config/controller_compilation.php'),
            ];
        }

        return $fragments;
    }

    private function flatten(string $prefix, array $value, string $location): array
    {
        $origin = new MetadataOrigin(
            provider: $this->name(),
            type: 'config',
            location: $location,
        );

        $fragments = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $composedKey = $prefix . '.' . $key;

            if (is_array($item)) {
                $fragments = [
                    ...$fragments,
                    ...$this->flatten($composedKey, $item, $location),
                ];
                continue;
            }

            $fragments[] = new MetadataFragment(
                key: $composedKey,
                value: $item,
                origin: $origin,
                priority: $this->priority(),
            );
        }

        return $fragments;
    }
}
