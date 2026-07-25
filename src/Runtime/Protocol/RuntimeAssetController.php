<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Protocol;

use Quantum\Controllers\Controller;
use Quantum\Http\Request;
use Quantum\Http\Response;

final class RuntimeAssetController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lastModifiedAt = filemtime(volt_runtime_path());
        $version = volt_runtime_version();
        $contents = volt_runtime_contents();
        $acceptEncoding = strtolower(trim((string) $request->header('Accept-Encoding', '')));
        $useGzip = false;
        $body = $contents;

        if (function_exists('gzencode') && str_contains($acceptEncoding, 'gzip')) {
            $encoded = gzencode($contents, 6, ZLIB_ENCODING_GZIP);

            if (is_string($encoded)) {
                $body = $encoded;
                $useGzip = true;
            }
        }

        $etag = '"volt-runtime-' . $version . ($useGzip ? '-gzip' : '') . '"';

        if ($this->matchesConditionalRequest($request, $etag, $lastModifiedAt)) {
            return $this->response('', 304, $this->headers($etag, $lastModifiedAt, $useGzip));
        }

        return $this->response(
            $body,
            200,
            $this->headers($etag, $lastModifiedAt, $useGzip, strlen($body)),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $etag, int|false $lastModifiedAt, bool $useGzip, ?int $contentLength = null): array
    {
        $headers = [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept-Encoding',
        ];

        if ($useGzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        if (is_int($contentLength)) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        if (is_int($lastModifiedAt)) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $lastModifiedAt) . ' GMT';
        }

        return $headers;
    }

    private function matchesConditionalRequest(Request $request, string $etag, int|false $lastModifiedAt): bool
    {
        $ifNoneMatch = trim((string) $request->header('If-None-Match', ''));

        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return true;
        }

        $ifModifiedSince = trim((string) $request->header('If-Modified-Since', ''));

        if ($ifModifiedSince === '' || ! is_int($lastModifiedAt)) {
            return false;
        }

        $requestedTimestamp = strtotime($ifModifiedSince);

        return $requestedTimestamp !== false && $requestedTimestamp >= $lastModifiedAt;
    }
}
