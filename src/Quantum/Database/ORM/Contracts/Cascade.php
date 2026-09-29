<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

/**
 * Canonical cascade operations used by ManyToOne and OneToMany attributes.
 * V1 supports PERSIST (auto persist associations) and REMOVE (auto remove associations).
 * MERGE and REFRESH are reserved constants for future DV-DB-015+ cycles.
 */
final class Cascade
{
    public const PERSIST = 'persist';
    public const REMOVE = 'remove';
    public const MERGE = 'merge';
    public const DETACH = 'detach';
    public const REFRESH = 'refresh';

    /**
     * Convenience alias: every supported cascade operation.
     * Use carefully on inverse OneToMany sides; never on the owning side of ManyToOne unless
     * you intentionally want bidirectional delete loops.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PERSIST,
        self::REMOVE,
    ];
}
