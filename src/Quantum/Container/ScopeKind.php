<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * @internal
 */
enum ScopeKind: string
{
    case Root = 'root';
    case Generic = 'scope';
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';
    case Tenant = 'tenant';
    case Worker = 'worker';

    public static function fromName(string $name): self
    {
        return match (strtolower(trim($name))) {
            'root' => self::Root,
            'request' => self::Request,
            'job' => self::Job,
            'command' => self::Command,
            'tenant' => self::Tenant,
            'worker' => self::Worker,
            default => self::Generic,
        };
    }
}
