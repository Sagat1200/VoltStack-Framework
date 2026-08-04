<?php

declare(strict_types=1);

namespace Quantum\Transport\Bridges\Http;

use Quantum\Http\Response;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Response\ResponseMetadata;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\ResponseBody\TextResponseBody;

final class HttpResponseTransformer
{
    public function transform(Response $response): ResponseInterface
    {
        $metadata = new ResponseMetadata(headers: $response->headers());

        return (new TransportResponse())
            ->withStatus($response->statusCode())
            ->withMetadata($metadata)
            ->withBody(new TextResponseBody($response->content()));
    }
}

