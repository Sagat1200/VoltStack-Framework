<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryAuditLoggerInterface;
use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface;
use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;

/**
 * Dispatcher compuesto de notificaciones de recovery.
 *
 * Resuelve el canal adecuado según el campo `channel` de la notificación,
 * aplica rate limiting por identity+type+channel, reintenta con backoff
 * exponencial y registra cada intento en el audit de recovery.
 *
 * Mantiene backward compat: implementa RecoveryNotificationDispatcherInterface,
 * por lo que puede sustituir a los drivers históricos file/noop sin cambiar
 * el contrato.
 */
final class CompositeRecoveryNotificationDispatcher implements RecoveryNotificationDispatcherInterface
{
    /**
     * Buckets de rate limit en memoria: key => list<int> de timestamps de envío.
     *
     * @var array<string, list<int>>
     */
    private array $rateBuckets = [];

    private readonly int $maxAttempts;
    private readonly int $baseDelayMs;
    private readonly int $maxDelayMs;
    private readonly int $maxPerWindow;
    private readonly int $windowSeconds;
    private readonly string $defaultChannel;
    private readonly ?string $fallbackChannel;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly RecoveryNotificationChannelRegistry $registry,
        private readonly ?RecoveryNotificationTemplateRendererInterface $templateRenderer = null,
        private readonly ?RecoveryAuditLoggerInterface $audit = null,
        array $config = [],
    ) {
        $retry = is_array($config['retry'] ?? null) ? $config['retry'] : [];
        $rate = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];

        $this->maxAttempts = isset($retry['max_attempts']) && is_numeric($retry['max_attempts'])
            ? max(1, (int) $retry['max_attempts'])
            : 3;
        $this->baseDelayMs = isset($retry['base_delay_ms']) && is_numeric($retry['base_delay_ms'])
            ? max(0, (int) $retry['base_delay_ms'])
            : 100;
        $this->maxDelayMs = isset($retry['max_delay_ms']) && is_numeric($retry['max_delay_ms'])
            ? max(0, (int) $retry['max_delay_ms'])
            : 5000;
        $this->maxPerWindow = isset($rate['max_per_window']) && is_numeric($rate['max_per_window'])
            ? max(0, (int) $rate['max_per_window'])
            : 5;
        $this->windowSeconds = isset($rate['window_seconds']) && is_numeric($rate['window_seconds'])
            ? max(1, (int) $rate['window_seconds'])
            : 300;
        $this->defaultChannel = is_string($config['default_channel'] ?? null) && trim((string) $config['default_channel']) !== ''
            ? trim((string) $config['default_channel'])
            : 'file';
        $this->fallbackChannel = is_string($config['fallback_channel'] ?? null) && trim((string) $config['fallback_channel']) !== ''
            ? trim((string) $config['fallback_channel'])
            : null;
    }

    public function dispatch(RecoveryNotification $notification): bool
    {
        $channel = $this->resolveChannel($notification->channel);

        if (! $channel instanceof RecoveryNotificationChannelInterface) {
            return false;
        }

        // Rate limiting: si se excede el límite, no enviamos y auditamos.
        if ($this->isRateLimited($notification)) {
            $this->auditDelivery(
                notification: $notification,
                channel: $channel->channelName(),
                success: false,
                attempt: 0,
                errorMessage: 'rate_limited',
            );

            return false;
        }

        $attempt = 0;
        $lastResult = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;
            $result = $channel->send($notification);
            $lastResult = $result;

            if ($result->success) {
                $this->recordRateLimitHit($notification);
                $this->auditDelivery(
                    notification: $notification,
                    channel: $channel->channelName(),
                    success: true,
                    attempt: $attempt,
                    providerResponse: $result->providerResponse,
                );

                return true;
            }

            // Backoff exponencial antes del siguiente intento.
            if ($attempt < $this->maxAttempts && $this->baseDelayMs > 0) {
                $delayMs = min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);
                usleep($delayMs * 1000);
            }
        }

        // Intentar canal de respaldo si está configurado y es distinto.
        if ($this->fallbackChannel !== null && $this->fallbackChannel !== $channel->channelName()) {
            $fallback = $this->registry->resolve($this->fallbackChannel);
            if ($fallback instanceof RecoveryNotificationChannelInterface) {
                $fallbackResult = $fallback->send($notification);
                if ($fallbackResult->success) {
                    $this->recordRateLimitHit($notification);
                    $this->auditDelivery(
                        notification: $notification,
                        channel: $fallback->channelName(),
                        success: true,
                        attempt: 1,
                        providerResponse: $fallbackResult->providerResponse,
                    );

                    return true;
                }
            }
        }

        $errorMessage = $lastResult instanceof RecoveryNotificationDeliveryResult
            ? ($lastResult->errorMessage ?? 'unknown error')
            : 'unknown error';

        $this->auditDelivery(
            notification: $notification,
            channel: $channel->channelName(),
            success: false,
            attempt: $attempt,
            errorMessage: $errorMessage,
            providerResponse: $lastResult instanceof RecoveryNotificationDeliveryResult ? $lastResult->providerResponse : null,
        );

        return false;
    }

    private function resolveChannel(string $requested): ?RecoveryNotificationChannelInterface
    {
        $requested = trim($requested);

        if ($requested !== '') {
            $channel = $this->registry->resolve($requested);
            if ($channel instanceof RecoveryNotificationChannelInterface) {
                return $channel;
            }
        }

        // Canal por defecto si el solicitado no está registrado.
        if ($this->defaultChannel !== '') {
            $default = $this->registry->resolve($this->defaultChannel);
            if ($default instanceof RecoveryNotificationChannelInterface) {
                return $default;
            }
        }

        return null;
    }

    private function isRateLimited(RecoveryNotification $notification): bool
    {
        if ($this->maxPerWindow <= 0) {
            return false;
        }

        $key = $this->rateLimitKey($notification);
        $now = time();
        $cutoff = $now - $this->windowSeconds;

        $hits = $this->rateBuckets[$key] ?? [];
        $hits = array_values(array_filter($hits, static fn (int $ts): bool => $ts >= $cutoff));
        $this->rateBuckets[$key] = $hits;

        return count($hits) >= $this->maxPerWindow;
    }

    private function recordRateLimitHit(RecoveryNotification $notification): void
    {
        if ($this->maxPerWindow <= 0) {
            return;
        }

        $key = $this->rateLimitKey($notification);
        $this->rateBuckets[$key][] = time();
    }

    private function rateLimitKey(RecoveryNotification $notification): string
    {
        return implode('|', [
            $notification->identityType,
            $notification->identityId,
            $notification->type->value,
            $notification->channel,
        ]);
    }

    private function auditDelivery(
        RecoveryNotification $notification,
        string $channel,
        bool $success,
        int $attempt,
        ?string $errorMessage = null,
        ?string $providerResponse = null,
    ): void {
        if (! $this->audit instanceof RecoveryAuditLoggerInterface) {
            return;
        }

        $identity = new \Quantum\Auth\Identity\IdentityReference(
            new \Quantum\Auth\Identity\IdentityIdentifier($notification->identityId),
            $notification->identityType,
        );

        $event = new RecoveryAuditEvent(
            eventId: bin2hex(random_bytes(12)),
            action: RecoveryAuditEvent::ACTION_DELIVERY_ATTEMPTED,
            purpose: $notification->purpose,
            occurredAt: time(),
            identity: $identity,
            recoveryTokenId: $notification->metadata['token_id'] ?? null,
            destination: $notification->destination,
            transport: $notification->transport,
            result: $success ? 'success' : 'failure',
            payload: [
                'channel' => $channel,
                'notification_type' => $notification->type->value,
                'attempt' => $attempt,
                'error_message' => $errorMessage,
                'provider_response' => $providerResponse,
            ],
            correlationId: $notification->metadata['correlation_id'] ?? null,
        );

        try {
            $this->audit->log($event);
        } catch (\Throwable) {
            // Best-effort: el audit no debe romper el delivery.
        }
    }
}
