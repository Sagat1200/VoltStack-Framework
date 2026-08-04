<?php

declare(strict_types=1);

namespace Quantum\Transport\Response;

use Quantum\Transport\Contracts\ResponseBodyInterface;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\ResponseBody\EmptyResponseBody;

final readonly class TransportResponse implements ResponseInterface
{
    private int $status;
    private ResponseBodyInterface $body;
    private ResponseMetadata $metadata;

    public function __construct(
        int $status = 200,
        ?ResponseBodyInterface $body = null,
        ?ResponseMetadata $metadata = null,
    ) {
        $this->status = $status;
        $this->body = $body ?? new EmptyResponseBody();
        $this->metadata = $metadata ?? new ResponseMetadata();
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): ResponseBodyInterface
    {
        return $this->body;
    }

    public function metadata(): ResponseMetadata
    {
        return $this->metadata;
    }

    public function withStatus(int $status): static
    {
        return new self(status: $status, body: $this->body, metadata: $this->metadata);
    }

    public function withBody(ResponseBodyInterface $body): static
    {
        return new self(status: $this->status, body: $body, metadata: $this->metadata);
    }

    public function withMetadata(ResponseMetadata $metadata): static
    {
        return new self(status: $this->status, body: $this->body, metadata: $metadata);
    }
}
