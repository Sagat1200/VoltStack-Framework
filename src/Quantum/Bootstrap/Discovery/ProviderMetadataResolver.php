<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Discovery;

use InvalidArgumentException;
use VoltStack\Framework\ServiceProvider;

final class ProviderMetadataResolver
{
    /**
     * @param class-string<ServiceProvider> $providerClass
     */
    public function resolve(string $providerClass): ProviderMetadata
    {
        $providerClass = ltrim(trim($providerClass), '\\');

        if ($providerClass === '') {
            throw new InvalidArgumentException('Provider class cannot be empty.');
        }

        if (class_exists($providerClass) && ! is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new InvalidArgumentException(sprintf(
                'Provider [%s] must extend [%s].',
                $providerClass,
                ServiceProvider::class,
            ));
        }

        if (method_exists($providerClass, 'metadata')) {
            $metadata = $providerClass::metadata();

            if (! $metadata instanceof ProviderMetadata) {
                throw new InvalidArgumentException(sprintf(
                    'Provider [%s] metadata() must return [%s].',
                    $providerClass,
                    ProviderMetadata::class,
                ));
            }

            return $metadata;
        }

        return ProviderMetadata::defaultFor($providerClass);
    }
}
