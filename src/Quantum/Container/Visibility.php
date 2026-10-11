<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * Visibilidad de un binding dentro del modelo de definiciones.
 *
 * - Public: el binding es resoluble desde cualquier contexto.
 * - Private: el binding solo es resoluble dentro de su modulo/origen.
 *
 * @internal
 */
enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';

    public static function fromName(string $name): self
    {
        return match (strtolower(trim($name))) {
            'private', 'internal' => self::Private,
            default => self::Public,
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Public;
    }

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
