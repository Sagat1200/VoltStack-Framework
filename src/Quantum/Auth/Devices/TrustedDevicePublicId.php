<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use InvalidArgumentException;

final readonly class TrustedDevicePublicId
{
    public function __construct(
        public string $value,
    ) {
        $value = trim($this->value);

        if ($value === '') {
            throw new InvalidArgumentException('TrustedDevicePublicId cannot be empty.');
        }

        if (! str_starts_with($value, 'tdv_')) {
            throw new InvalidArgumentException('TrustedDevicePublicId must use the tdv_ prefix.');
        }
    }

    public static function generate(): self
    {
        return new self('tdv_' . bin2hex(random_bytes(12)));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
