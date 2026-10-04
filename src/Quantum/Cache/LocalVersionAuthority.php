<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\VersionAuthorityInterface;

final class LocalVersionAuthority implements VersionAuthorityInterface
{
    /**
     * @var array<string, int>
     */
    private array $versions = [];

    public function currentVersion(string $scope): string
    {
        return 'v' . ($this->versions[$scope] ?? 1);
    }

    public function bump(string $scope): string
    {
        $current = $this->versions[$scope] ?? 1;
        $current++;
        $this->versions[$scope] = $current;

        return 'v' . $current;
    }
}
