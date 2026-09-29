<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Exceptions;

final class UnsupportedCoseAlgorithmException extends \InvalidArgumentException
{
    public static function forAlg(int $alg): self
    {
        $supported = implode(', ', ['-7 (ES256 P-256)', '-257 (RS256 RSA 2048)']);
        return new self(sprintf('Unsupported COSE algorithm "%d". Supported: %s', $alg, $supported));
    }
}
