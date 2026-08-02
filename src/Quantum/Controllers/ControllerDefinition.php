<?php

declare(strict_types=1);

namespace Quantum\Controllers;

final class ControllerDefinition
{
    public function __construct(private readonly mixed $action) {}

    public function action(): mixed
    {
        return $this->action;
    }
}

