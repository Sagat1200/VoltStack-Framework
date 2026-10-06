<?php

declare(strict_types=1);

namespace Quantum\Http;

use JsonException;
use RuntimeException;

final class JsonResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(mixed $data, int $statusCode = 200, array $headers = [])
    {
        try {
            $content = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode JSON response.', 0, $exception);
        }

        parent::__construct($content, $statusCode, ['Content-Type' => 'application/json; charset=UTF-8', ...$headers]);
    }
}
