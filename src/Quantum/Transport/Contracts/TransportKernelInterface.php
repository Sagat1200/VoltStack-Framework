<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Http\Request;

interface TransportKernelInterface
{
    public function handle(Request $request): ResponseInterface;
}

