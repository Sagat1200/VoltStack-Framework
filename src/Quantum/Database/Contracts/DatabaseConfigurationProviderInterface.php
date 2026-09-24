<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use Quantum\Database\Config\DatabaseConfiguration;

interface DatabaseConfigurationProviderInterface
{
    public function configuration(): DatabaseConfiguration;
}
