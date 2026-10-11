<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;

/**
 * Canal de notificación webhook (POST HTTP con payload JSON).
 *
 * Diseñado para integrarse con servicios externos que reciben eventos de
 * recovery (alertas de seguridad, dashboards, etc.). Usa curl vanilla sin
 * dependencias externas. Las cabeceras y el timeout son configurables.
 */
final class WebhookRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function channelName(): string
    {
        return 'webhook';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        $url = trim($this->url);

        if ($url === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'webhook',
                destination: $notification->destination,
                errorMessage: 'webhook url is empty',
            );
        }

        try {
            $payload = json_encode(
                $notification->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            $defaultHeaders = [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'Accept: application/json',
            ];

            $customHeaders = [];
            foreach ($this->headers as $name => $value) {
                $customHeaders[] = $name . ': ' . $value;
            }

            $headers = array_merge($defaultHeaders, $customHeaders);

            $ch = curl_init($url);
            if ($ch === false) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'webhook',
                    destination: $notification->destination,
                    errorMessage: 'curl_init failed',
                );
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
                CURLOPT_CONNECTTIMEOUT => max(1, $this->timeoutSeconds),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $error !== '') {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'webhook',
                    destination: $notification->destination,
                    errorMessage: $error !== '' ? $error : 'curl_exec returned false',
                    providerResponse: is_string($response) ? $response : null,
                );
            }

            // Consideramos éxito cualquier respuesta 2xx.
            if ($httpCode < 200 || $httpCode >= 300) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'webhook',
                    destination: $notification->destination,
                    errorMessage: 'HTTP ' . $httpCode,
                    providerResponse: is_string($response) ? substr($response, 0, 1024) : null,
                );
            }

            return RecoveryNotificationDeliveryResult::success(
                channel: 'webhook',
                destination: $notification->destination,
                providerResponse: is_string($response) ? substr($response, 0, 1024) : null,
                providerResponseData: ['http_code' => $httpCode],
            );
        } catch (\Throwable $e) {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'webhook',
                destination: $notification->destination,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
