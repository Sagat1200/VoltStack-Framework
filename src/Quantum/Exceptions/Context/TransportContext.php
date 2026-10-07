<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Context;

final readonly class TransportContext
{
    /**
     * @param list<string> $accept
     */
    public function __construct(
        public string $kind,
        public bool $committed = false,
        public ?string $routeProfile = null,
        public array $accept = [],
        public ?string $locale = null,
        public ?int $spaVersion = null,
    ) {
        if ($kind === '') {
            throw new \InvalidArgumentException('Transport kind must not be empty.');
        }

        if ($spaVersion !== null && $spaVersion < 1) {
            throw new \InvalidArgumentException('Transport spaVersion must be greater than zero.');
        }
    }
}
