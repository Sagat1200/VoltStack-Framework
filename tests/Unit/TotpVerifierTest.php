<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Mfa\TotpVerifier;

final class TotpVerifierTest extends TestCase
{
    public function test_it_generates_and_verifies_rfc6238_sha1_vector(): void
    {
        $verifier = new TotpVerifier();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $code = $verifier->generate($secret, 59, 30, 8, 'sha1');

        self::assertSame('94287082', $code);
        self::assertTrue($verifier->verify($secret, '94287082', 59, 30, 8, 0, 'sha1'));
        self::assertFalse($verifier->verify($secret, '94287081', 59, 30, 8, 0, 'sha1'));
    }
}
