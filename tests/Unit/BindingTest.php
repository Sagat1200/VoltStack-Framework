<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quantum\Container\Binding;
use Quantum\Container\ScopeKind;
use Quantum\Container\Visibility;

final class BindingTest extends TestCase
{
    public function test_factory_helpers_expose_the_expected_lifetimes(): void
    {
        $transient = Binding::transient('transient.service', 'transient.service');
        $singleton = Binding::singleton('singleton.service', 'singleton.service');
        $scoped = Binding::scoped('scoped.service', 'scoped.service');
        $requestScoped = Binding::scoped('request.service', 'request.service', ScopeKind::Request);

        self::assertSame('transient', $transient->lifetime());
        self::assertFalse($transient->storesResolvedInstance());

        self::assertSame('singleton', $singleton->lifetime());
        self::assertTrue($singleton->storesResolvedInstance());

        self::assertSame('scoped', $scoped->lifetime());
        self::assertTrue($scoped->storesResolvedInstance());
        self::assertNull($scoped->scopeKindName());

        self::assertSame('scoped', $requestScoped->lifetime());
        self::assertTrue($requestScoped->storesResolvedInstance());
        self::assertSame('request', $requestScoped->scopeKindName());
    }

    public function test_binding_cannot_be_shared_and_scoped_at_the_same_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be shared and scoped');

        new Binding('invalid.service', 'invalid.service', true, true);
    }

    public function test_binding_defaults_to_public_visibility_when_not_declared(): void
    {
        $binding = Binding::transient('public.service', 'public.service');

        self::assertTrue($binding->isPublic());
        self::assertFalse($binding->isPrivate());
        self::assertSame(Visibility::Public, $binding->visibility());
    }

    public function test_binding_can_declare_private_visibility(): void
    {
        $binding = Binding::singleton('private.service', 'private.service', Visibility::Private);

        self::assertFalse($binding->isPublic());
        self::assertTrue($binding->isPrivate());
        self::assertSame(Visibility::Private, $binding->visibility());
    }

    public function test_scoped_binding_can_declare_private_visibility_without_losing_scope_kind(): void
    {
        $binding = Binding::scoped('private.scoped', 'private.scoped', ScopeKind::Request, Visibility::Private);

        self::assertSame('request', $binding->scopeKindName());
        self::assertTrue($binding->isPrivate());
    }

    public function test_binding_has_no_origin_by_default(): void
    {
        $binding = Binding::transient('service', 'service');

        self::assertFalse($binding->hasOrigin());
        self::assertNull($binding->origin());
    }

    public function test_binding_can_carry_origin_for_traceability(): void
    {
        $binding = Binding::singleton('service', 'service', origin: 'AppServiceProvider::register');

        self::assertTrue($binding->hasOrigin());
        self::assertSame('AppServiceProvider::register', $binding->origin());
    }

    public function test_visibility_from_name_resolves_known_values(): void
    {
        self::assertSame(Visibility::Public, Visibility::fromName('public'));
        self::assertSame(Visibility::Private, Visibility::fromName('private'));
        self::assertSame(Visibility::Private, Visibility::fromName('internal'));
        self::assertSame(Visibility::Public, Visibility::fromName('unknown'));
    }
}
