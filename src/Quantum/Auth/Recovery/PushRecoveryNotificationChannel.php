<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;

/**
 * Canal de notificación push a través de un gateway HTTP genérico.
 *
 * Compatible con servicios como Firebase Cloud Messaging (FCM), Apple Push
 * Notification service (APNs) o cualquier proveedor que exponga un endpoint
 * HTTP. La URL, cabeceras, API key y los nombres de los parámetros del cuerpo
 * (token de dispositivo, título y cuerpo) son configurables.
 *
 * El título y el cuerpo se obtienen del template renderer (canal "push"); si
 * no hay renderer, se construyen desde el payload.
 */
final class PushRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly ?RecoveryNotificationTemplateRendererInterface $templateRenderer = null,
        private readonly string $gatewayUrl = '',
        private readonly array $headers = [],
        private readonly string $apiKey = '',
        private readonly int $timeoutSeconds = 10,
        private readonly string $tokenParam = 'token',
        private readonly string $titleParam = 'title',
        private readonly string $bodyParam = 'body',
    ) {}

    public function channelName(): string
    {
        return 'push';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        $token = trim($notification->destination);
        $url = trim($this->gatewayUrl);

        if ($token === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'push',
                destination: $notification->destination,
                errorMessage: 'push device token is empty',
            );
        }

        if ($url === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'push',
                destination: $token,
                errorMessage: 'push gateway url is empty',
            );
        }

        try {
            $template = $this->renderTemplate($notification);

            $title = $template->subject !== '' ? $template->subject : 'Recovery notification';
            $body = $template->body !== '' ? $template->body : $this->buildFallbackBody($notification);

            $payload = [
                $this->tokenParam => $token,
                $this->titleParam => $title,
                $this->bodyParam => $body,
            ];

            $response = $this->sendHttpRequest($url, $payload);

            if (! $response['success']) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'push',
                    destination: $token,
                    errorMessage: $response['error'],
                    providerResponse: $response['body'],
                    providerResponseData: ['http_code' => $response['http_code']],
                );
            }

            return RecoveryNotificationDeliveryResult::success(
                channel: 'push',
                destination: $token,
                providerResponse: $response['body'],
                providerResponseData: ['http_code' => $response['http_code']],
            );
        } catch (\Throwable $e) {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'push',
                destination: $token,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function renderTemplate(RecoveryNotification $notification): RecoveryNotificationTemplate
    {
        if ($this->templateRenderer instanceof RecoveryNotificationTemplateRendererInterface) {
            try {
                return $this->templateRenderer->render(
                    $notification->type,
                    'push',
                    $notification->payload,
                );
            } catch (\Throwable) {
                // Fall through.
            }
        }

        return new RecoveryNotificationTemplate(
            subject: '',
            body: '',
            format: RecoveryNotificationTemplate::FORMAT_TEXT,
        );
    }

    private function buildFallbackBody(RecoveryNotification $notification): string
    {
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

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $defaultHeaders = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($encodedPayload),
        ];

        $customHeaders = [];
        foreach ($this->headers as $name => $value) {
            $customHeaders[] = $name . ': ' . $value;
        }

        if ($this->apiKey !== '') {
            $customHeaders[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $headers = array_merge($defaultHeaders, $customHeaders);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
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
