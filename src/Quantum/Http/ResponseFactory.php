<?php

declare(strict_types=1);

namespace Quantum\Http;

final class ResponseFactory
{
    /**
     * @param array<string, string> $headers
     */
    public function make(string $content = '', int $statusCode = 200, array $headers = []): Response
    {
        return new Response($content, $statusCode, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function json(mixed $data, int $statusCode = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $statusCode, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function redirect(string $location, int $statusCode = 302, array $headers = []): RedirectResponse
    {
        return new RedirectResponse($location, $statusCode, $headers);
    }
}