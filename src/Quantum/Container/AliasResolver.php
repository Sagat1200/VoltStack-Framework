<?php

declare(strict_types=1);

namespace Quantum\Container;

use Quantum\Container\Exceptions\BindingResolutionException;

/**
 * @internal
 */
final class AliasResolver
{
    /**
     * @param array<string, string> $aliases
     */
    public function normalize(string $abstract, array $aliases): string
    {
        $seen = [];

        while (isset($aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new BindingResolutionException(sprintf('Circular alias detected for [%s].', $abstract));
            }

            $seen[$abstract] = true;
            $abstract = $aliases[$abstract];
        }

        return $abstract;
    }
}
