<?php

declare(strict_types=1);

namespace Quantum\Compilation\Contracts;

use Quantum\Compilation\Build;

interface BuildManifestInterface
{
    public function create(): Build;

    public function get(string $buildId): ?Build;

    public function current(): ?Build;

    public function setCurrent(string $buildId): Build;

    public function all(): array;

    public function prune(int $retain = 3): array;

    public function previous(): ?Build;

    public function rollback(): ?Build;
}
