<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

interface IdentityInterface
{
    public function identifier(): IdentityIdentifier;

    public function type(): string;
}