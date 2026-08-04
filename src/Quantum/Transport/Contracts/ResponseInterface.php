<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Response\ResponseMetadata;

interface ResponseInterface
{
    public function status(): int;

    public function body(): ResponseBodyInterface;

    public function metadata(): ResponseMetadata;

    public function withStatus(int $status): static;

    public function withBody(ResponseBodyInterface $body): static;

    public function withMetadata(ResponseMetadata $metadata): static;
}

