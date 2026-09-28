<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface InventoryReconcilerInterface
{
    /**
     * @param int|null $now
     * @param bool $dryRun
     *
     * @return array{
     *     scanned: int,
     *     updated: int,
     *     promoted: int,
     *     demoted: int,
     *     skipped_expired: int,
     *     skipped_without_device: int,
     *     evaluated_at: int,
     * }
     */
    public function reconcile(?int $now = null, bool $dryRun = false): array;
}
