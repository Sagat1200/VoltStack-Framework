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

    public function canRetain(self $dependencyKind): bool
    {
        return match ($this) {
            self::Root => $dependencyKind === self::Root,
            self::Worker => in_array($dependencyKind, [self::Worker], true),
            self::Request => in_array($dependencyKind, [self::Request, self::Worker], true),
            self::Tenant => in_array($dependencyKind, [self::Tenant, self::Request, self::Worker], true),
            self::Job => in_array($dependencyKind, [self::Job, self::Worker], true),
            self::Command => in_array($dependencyKind, [self::Command, self::Worker], true),
            self::Generic => in_array($dependencyKind, [self::Generic], true),
        };
    }
}
