<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Reset;

use VoltStack\Framework\Application;

interface ResettableInterface
{
    public function reset(Application $app): void;
}
