<?php

declare(strict_types=1);

namespace Quantum\Http;

final class RedirectResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $location, int $statusCode = 302, array $headers = [])
    {
        parent::__construct('', $statusCode, ['Location' => $location, ...$headers]);
    }
}
