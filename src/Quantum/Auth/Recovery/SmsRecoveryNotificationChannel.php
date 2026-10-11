<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;

/**
 * Canal de notificación por SMS a través de un gateway HTTP genérico.
 *
 * Diseñado para ser compatible con cualquier proveedor de SMS que exponga un
 * endpoint HTTP (Twilio, Vonage, MessageBird, proveedores locales, etc.). La
 * URL, el método HTTP, las cabeceras y los nombres de los parámetros del
 * cuerpo (destinatario y mensaje) son configurables para adaptarse a cada
 * proveedor.
 *
 * El cuerpo del SMS se obtiene del template renderer (canal "sms"); si no hay
 * renderer, se construye una versión reducida del payload.
 */
final class SmsRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly ?RecoveryNotificationTemplateRendererInterface $templateRenderer = null,
        private readonly string $gatewayUrl = '',
        private readonly string $method = 'POST',
        private readonly array $headers = [],
        private readonly string $apiKey = '',
        private readonly string $from = '',
        private readonly int $timeoutSeconds = 10,
        private readonly string $toParam = 'to',
        private readonly string $bodyParam = 'body',
        private readonly ?string $fromParam = 'from',
    ) {}

    public function channelName(): string
    {
        return 'sms';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        $to = trim($notification->destination);
        $url = trim($this->gatewayUrl);

        if ($to === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'sms',
                destination: $notification->destination,
                errorMessage: 'sms destination is empty',
            );
        }

        if ($url === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'sms',
                destination: $to,
                errorMessage: 'sms gateway url is empty',
            );
        }

        try {
            $body = $this->renderBody($notification);

            $payload = [
                $this->toParam => $to,
                $this->bodyParam => $body,
            ];

            if ($this->fromParam !== null && trim($this->from) !== '') {
                $payload[$this->fromParam] = $this->from;
            }

            $response = $this->sendHttpRequest($url, $payload);

            if (! $response['success']) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'sms',
                    destination: $to,
                    errorMessage: $response['error'],
                    providerResponse: $response['body'],
                    providerResponseData: ['http_code' => $response['http_code']],
                );
            }

            return RecoveryNotificationDeliveryResult::success(
                channel: 'sms',
                destination: $to,
                providerResponse: $response['body'],
                providerResponseData: ['http_code' => $response['http_code']],
            );
        } catch (\Throwable $e) {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'sms',
                destination: $to,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function renderBody(RecoveryNotification $notification): string
    {
        if ($this->templateRenderer instanceof RecoveryNotificationTemplateRendererInterface) {
            try {
                $template = $this->templateRenderer->render(
                    $notification->type,
                    'sms',
                    $notification->payload,
                );

                if ($template->body !== '') {
                    return $template->body;
                }
            } catch (\Throwable) {
                // Fall through.
            }
        }

        // Fallback: construir un cuerpo corto desde el payload.
        $parts = [];
        foreach ($notification->payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key . ': ' . (string) $value;
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, error: string, body: ?string, http_code: int}
     */
    private function sendHttpRequest(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'error' => 'curl_init failed', 'body' => null, 'http_code' => 0];
        }

        $isJson = strtoupper($this->method) === 'POST'
            && (! isset($this->headers['Content-Type']) || stripos((string) $this->headers['Content-Type'], 'json') !== false);

        $encodedPayload = $isJson
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : http_build_query($payload);

        $defaultHeaders = $isJson
            ? ['Content-Type: application/json']
            : ['Content-Type: application/x-www-form-urlencoded'];

        $customHeaders = [];
        foreach ($this->headers as $name => $value) {
            $customHeaders[] = $name . ': ' . $value;
        }

        if ($this->apiKey !== '') {
            $customHeaders[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $headers = array_merge($defaultHeaders, $customHeaders);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($this->method),
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, $this->timeoutSeconds),
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $error !== '') {
            return [
                'success' => false,
                'error' => $error !== '' ? $error : 'curl_exec returned false',
                'body' => is_string($responseBody) ? $responseBody : null,
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'error' => 'HTTP ' . $httpCode,
                'body' => is_string($responseBody) ? substr($responseBody, 0, 1024) : null,
                'http_code' => $httpCode,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'body' => is_string($responseBody) ? substr($responseBody, 0, 1024) : null,
            'http_code' => $httpCode,
        ];
    }
}
