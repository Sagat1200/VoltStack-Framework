<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Ability\Ability;

interface AbilityNormalizerInterface
{
    public function normalize(string|Ability $ability): Ability;
}
