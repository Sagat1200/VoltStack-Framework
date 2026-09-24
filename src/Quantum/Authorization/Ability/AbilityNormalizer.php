<?php

declare(strict_types=1);

namespace Quantum\Authorization\Ability;

use Quantum\Authorization\Contracts\AbilityNormalizerInterface;

final class AbilityNormalizer implements AbilityNormalizerInterface
{
    public function normalize(string|Ability $ability): Ability
    {
        return $ability instanceof Ability
            ? $ability
            : new Ability($ability);
    }
}
