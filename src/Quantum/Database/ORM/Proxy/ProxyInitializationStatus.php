<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Proxy;

/**
 * Three-phase lifecycle of a lazy placeholder managed by the EntityManager.
 *
 * - Uninitialized: object returned by EntityManager::getReference() without a DB row hit.
 * - Initializing: transient state during initializeProxy()/refresh(); re-entrant calls are rejected.
 * - Initialized: a real snapshot has been loaded and UnitOfWork snapshots are populated.
 */
enum ProxyInitializationStatus
{
    case Uninitialized;
    case Initializing;
    case Initialized;
}
