<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Proxy;

/**
 * Marker interface implemented by uninitialized lazy placeholders.
 *
 * Entity user-land classes are NOT required to implement this interface. It is
 * applied automatically to the anonymous subclass returned by
 * ReflectionAnonymousProxyInstantiator when the EntityManager is asked for a
 * reference via getReference().
 *
 * The marker lets UnitOfWork and EntityManager detect proxy placeholders
 * without relying on duck typing or reflection class names.
 */
interface LazyLoadingPlaceholderInterface
{
}
