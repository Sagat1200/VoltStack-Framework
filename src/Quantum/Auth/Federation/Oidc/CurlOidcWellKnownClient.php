<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcWellKnownClientInterface;

final class CurlOidcWellKnownClient implements OidcWellKnownClientInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly bool $enabled = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fetchConfiguration(string $issuerUrl): OidcProviderMetadata
    {
        if (! $this->enabled) {
            throw new \RuntimeException(sprintf(
                'CurlOidcWellKnownClient is disabled by default (auth.oidc.http_client.enabled=false), cannot fetch OIDC well-known from %s',
                $issuerUrl,
            ));
        }

        $wellKnownUrl = rtrim($issuerUrl, '/') . '/.well-known/openid-configuration';
        $body = $this->httpGet($wellKnownUrl);
        $config = json_decode($body, true);
        if (! is_array($config)) {
            throw new \RuntimeException(sprintf('OIDC well-known JSON invalid from %s', $wellKnownUrl));
        }

        $issuer = (string) ($config['issuer'] ?? $issuerUrl);
        $authEndpoint = (string) ($config['authorization_endpoint'] ?? '');
        $tokenEndpoint = (string) ($config['token_endpoint'] ?? '');
        $userinfoEndpoint = (string) ($config['userinfo_endpoint'] ?? '');
        $jwksUri = (string) ($config['jwks_uri'] ?? '');
        $algs = is_array($config['id_token_signing_alg_values_supported'] ?? null)
            ? array_values(array_filter($config['id_token_signing_alg_values_supported'], static fn (mixed $a): bool => is_string($a)))
            : ['RS256'];

        return new OidcProviderMetadata(
            issuer: $issuer,
            authorizationEndpoint: $authEndpoint,
            tokenEndpoint: $tokenEndpoint,
            userinfoEndpoint: $userinfoEndpoint,
            jwksUri: $jwksUri,
            idTokenSigningAlgValuesSupported: $algs,
        );
    }

    /**
     * @return array{keys:list<array<string, mixed>>}
     */
    public function fetchJwksByUri(string $jwksUri): array
    {
        if (! $this->enabled) {
            throw new \RuntimeException(sprintf(
                'CurlOidcWellKnownClient is disabled, cannot fetch JWKS from %s',
                $jwksUri,
            ));
        }
        $body = $this->httpGet($jwksUri);
        $data = json_decode($body, true);
        $keys = is_array($data['keys'] ?? null) ? array_values($data['keys']) : [];
        return ['keys' => $keys];
    }

    private function httpGet(string $url): string
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('curl extension is required for CurlOidcWellKnownClient');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException(sprintf('Unable to init curl for %s', $url));
        }
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'VoltStack-Auth-CurlOidcWellKnownClient/1.0',
            ]);
            $result = curl_exec($ch);
            if ($result === false) {
                throw new \RuntimeException(sprintf('curl error fetching %s: %s', $url, curl_error($ch)));
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 400) {
                throw new \RuntimeException(sprintf('HTTP %d fetching %s', $code, $url));
            }
            return (string) $result;
        } finally {
            curl_close($ch);
        }
    }
}
