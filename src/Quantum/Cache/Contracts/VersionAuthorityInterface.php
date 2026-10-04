<?php

declare(strict_types=1);

namespace Quantum\Cache\Contracts;

interface VersionAuthorityInterface
{
    public function currentVersion(string $scope): string;

    public function bump(string $scope): string;
}
