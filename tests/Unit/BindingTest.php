<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quantum\Container\Binding;

final class BindingTest extends TestCase
{
    public function test_factory_helpers_expose_the_expected_lifetimes(): void
    {
        $transient = Binding::transient('transient.service', 'transient.service');
        $singleton = Binding::singleton('singleton.service', 'singleton.service');
        $scoped = Binding::scoped('scoped.service', 'scoped.service');

        self::assertSame('transient', $transient->lifetime());
        self::assertFalse($transient->storesResolvedInstance());

        self::assertSame('singleton', $singleton->lifetime());
        self::assertTrue($singleton->storesResolvedInstance());

        self::assertSame('scoped', $scoped->lifetime());
        self::assertTrue($scoped->storesResolvedInstance());
    }

    public function test_binding_cannot_be_shared_and_scoped_at_the_same_time(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be shared and scoped');

        new Binding('invalid.service', 'invalid.service', true, true);
    }
}
