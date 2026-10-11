<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Resultado de un intento de envío de notificación de recovery.
 *
 * Inmutable; describe si el envío fue exitoso, el canal/destino usados,
 * el número de intento y, en caso de fallo, el mensaje de error y la
 * respuesta del proveedor (si aplica). Se usa para audit durable de delivery
 * y para la lógica de retry/backoff del dispatcher compuesto.
 */
final readonly class RecoveryNotificationDeliveryResult
{
    /**
     * @param array<string, mixed> $providerResponse
     */
    public function __construct(
        public bool $success,
        public int $attemptedAt,
        public string $channel,
        public string $destination,
        public int $attempt = 1,
        public ?string $errorMessage = null,
        public ?string $providerResponse = null,
        public array $providerResponseData = [],
    ) {}

    public static function success(
        string $channel,
        string $destination,
        int $attempt = 1,
        ?string $providerResponse = null,
        array $providerResponseData = [],
    ): self {
        return new self(
            success: true,
            attemptedAt: time(),
            channel: $channel,
            destination: $destination,
            attempt: $attempt,
            errorMessage: null,
            providerResponse: $providerResponse,
            providerResponseData: $providerResponseData,
        );
    }

    public static function failure(
        string $channel,
        string $destination,
        string $errorMessage,
        int $attempt = 1,
        ?string $providerResponse = null,
        array $providerResponseData = [],
    ): self {
        return new self(
            success: false,
            attemptedAt: time(),
            channel: $channel,
            destination: $destination,
            attempt: $attempt,
            errorMessage: $errorMessage,
            providerResponse: $providerResponse,
            providerResponseData: $providerResponseData,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'attempted_at' => $this->attemptedAt,
            'channel' => $this->channel,
            'destination' => $this->destination,
            'attempt' => $this->attempt,
            'error_message' => $this->errorMessage,
            'provider_response' => $this->providerResponse,
            'provider_response_data' => $this->providerResponseData,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: isset($data['success']) && $data['success'] === true,
            attemptedAt: isset($data['attempted_at']) && is_numeric($data['attempted_at'])
                ? (int) $data['attempted_at']
                : time(),
            channel: is_string($data['channel'] ?? null) ? $data['channel'] : '',
            destination: is_string($data['destination'] ?? null) ? $data['destination'] : '',
            attempt: isset($data['attempt']) && is_numeric($data['attempt']) ? (int) $data['attempt'] : 1,
            errorMessage: is_string($data['error_message'] ?? null) ? $data['error_message'] : null,
            providerResponse: is_string($data['provider_response'] ?? null) ? $data['provider_response'] : null,
            providerResponseData: isset($data['provider_response_data']) && is_array($data['provider_response_data'])
                ? $data['provider_response_data']
                : [],
        );
    }
}
