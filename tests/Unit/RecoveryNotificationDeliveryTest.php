<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use Quantum\Auth\Recovery\CompositeRecoveryNotificationDispatcher;
use Quantum\Auth\Recovery\ConfigRecoveryNotificationTemplateRenderer;
use Quantum\Auth\Recovery\FileRecoveryNotificationChannel;
use Quantum\Auth\Recovery\NoopRecoveryNotificationChannel;
use Quantum\Auth\Recovery\RecoveryNotification;
use Quantum\Auth\Recovery\RecoveryNotificationChannelRegistry;
use Quantum\Auth\Recovery\RecoveryNotificationDeliveryResult;
use Quantum\Auth\Recovery\RecoveryNotificationTemplate;
use Quantum\Auth\Recovery\RecoveryNotificationType;
use Quantum\Auth\Recovery\RecoveryPurpose;
use Quantum\Auth\Recovery\RecoveryAuditEvent;

/**
 * Pruebas unitarias del Bloque 7: Recovery Notification Delivery Governance.
 *
 * Cubre DeliveryResult, ChannelRegistry, canales (file/noop), template
 * renderer y CompositeRecoveryNotificationDispatcher con retry/backoff,
 * rate limiting, fallback channel y delivery audit.
 */
final class RecoveryNotificationDeliveryTest extends TestCase
{
    private function makeNotification(string $channel = 'noop', array $metadata = []): RecoveryNotification
    {
        return new RecoveryNotification(
            type: RecoveryNotificationType::PasswordResetRequested,
            purpose: RecoveryPurpose::PasswordReset,
            identityType: 'user',
            identityId: 'user-123',
            destination: 'user@example.com',
            channel: $channel,
            transport: 'unit',
            createdAt: time(),
            payload: ['reset_url' => 'https://example.com/reset/abc'],
            metadata: $metadata,
        );
    }

    public function test_delivery_result_success_and_failure_factories(): void
    {
        $success = RecoveryNotificationDeliveryResult::success('webhook', 'user@example.com');
        self::assertTrue($success->success);
        self::assertSame('webhook', $success->channel);
        self::assertSame(1, $success->attempt);
        self::assertNull($success->errorMessage);

        $failure = RecoveryNotificationDeliveryResult::failure(
            'webhook',
            'user@example.com',
            'connection timeout',
            attempt: 2,
        );
        self::assertFalse($failure->success);
        self::assertSame('connection timeout', $failure->errorMessage);
        self::assertSame(2, $failure->attempt);
    }

    public function test_delivery_result_serialization_round_trip(): void
    {
        $result = RecoveryNotificationDeliveryResult::success(
            'file',
            'user@example.com',
            attempt: 3,
            providerResponse: '/tmp/notifications.jsonl',
            providerResponseData: ['bytes' => 256],
        );

        $restored = RecoveryNotificationDeliveryResult::fromArray($result->toArray());

        self::assertTrue($restored->success);
        self::assertSame('file', $restored->channel);
        self::assertSame(3, $restored->attempt);
        self::assertSame('/tmp/notifications.jsonl', $restored->providerResponse);
        self::assertSame(['bytes' => 256], $restored->providerResponseData);
    }

    public function test_channel_registry_register_resolve_has_all(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        $noop = new NoopRecoveryNotificationChannel();

        self::assertFalse($registry->has('noop'));
        $registry->register($noop);
        self::assertTrue($registry->has('noop'));

        $resolved = $registry->resolve('noop');
        self::assertSame($noop, $resolved);
        self::assertCount(1, $registry->all());

        self::assertNull($registry->resolve('nonexistent'));
    }

    public function test_file_channel_writes_jsonl_and_returns_success(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'recovery-notif-');
        self::assertNotFalse($tempFile);
        @unlink($tempFile);

        try {
            $channel = new FileRecoveryNotificationChannel($tempFile);
            $result = $channel->send($this->makeNotification('file'));

            self::assertTrue($result->success);
            self::assertSame('file', $result->channel);
            self::assertFileExists($tempFile);

            $contents = file_get_contents($tempFile);
            self::assertNotFalse($contents);
            $decoded = json_decode($contents, true);
            self::assertIsArray($decoded);
            self::assertSame('password_reset_requested', $decoded['type']);
            self::assertSame('user@example.com', $decoded['destination']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_noop_channel_always_success(): void
    {
        $channel = new NoopRecoveryNotificationChannel();
        $result = $channel->send($this->makeNotification());

        self::assertTrue($result->success);
        self::assertSame('noop', $result->channel);
        self::assertNull($result->errorMessage);
    }

    public function test_template_renderer_uses_builtin_template_and_interpolates_variables(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'file',
            ['reset_url' => 'https://example.com/reset/xyz'],
        );

        self::assertInstanceOf(RecoveryNotificationTemplate::class, $template);
        self::assertStringContainsString('Solicitud de restablecimiento', $template->subject);
        self::assertStringContainsString('https://example.com/reset/xyz', $template->body);
        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $template->format);
    }

    public function test_template_renderer_overrides_with_config_template(): void
    {
        $configTemplates = [
            RecoveryNotificationType::PasswordResetRequested->value => [
                'file' => [
                    'subject' => 'Custom subject {{identity_id}}',
                    'body' => 'Custom body for {{identity_id}}',
                    'format' => RecoveryNotificationTemplate::FORMAT_HTML,
                ],
            ],
        ];

        $renderer = new ConfigRecoveryNotificationTemplateRenderer($configTemplates);
        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'file',
            ['identity_id' => 'user-123'],
        );

        self::assertSame('Custom subject user-123', $template->subject);
        self::assertSame('Custom body for user-123', $template->body);
        self::assertSame(RecoveryNotificationTemplate::FORMAT_HTML, $template->format);
    }

    public function test_template_renderer_email_builtin_is_html_and_contains_reset_link(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'email',
            ['reset_url' => 'https://example.com/reset/abc', 'expires_at' => '2026-12-31'],
        );

        self::assertSame(RecoveryNotificationTemplate::FORMAT_HTML, $template->format);
        self::assertStringContainsString('Restablece tu contraseña', $template->subject);
        self::assertStringContainsString('https://example.com/reset/abc', $template->body);
        self::assertStringContainsString('<html', $template->body);
        self::assertStringContainsString('2026-12-31', $template->body);
    }

    public function test_template_renderer_sms_builtin_is_short_text(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'sms',
            ['reset_url' => 'https://example.com/reset/abc', 'expires_at' => '2026-12-31'],
        );

        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $template->format);
        self::assertStringContainsString('https://example.com/reset/abc', $template->body);
        self::assertLessThan(200, strlen($template->body));
    }

    public function test_template_renderer_push_builtin_is_short_text(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'push',
            ['expires_at' => '2026-12-31'],
        );

        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $template->format);
        self::assertStringContainsString('Restablece tu contraseña', $template->subject);
        self::assertStringContainsString('2026-12-31', $template->body);
        self::assertLessThan(160, strlen($template->body));
    }

    public function test_template_renderer_password_reset_completed_has_channel_variants(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $email = $renderer->render(RecoveryNotificationType::PasswordResetCompleted, 'email');
        self::assertSame(RecoveryNotificationTemplate::FORMAT_HTML, $email->format);
        self::assertStringContainsString('<html', $email->body);

        $sms = $renderer->render(RecoveryNotificationType::PasswordResetCompleted, 'sms');
        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $sms->format);
        self::assertLessThan(140, strlen($sms->body));

        $push = $renderer->render(RecoveryNotificationType::PasswordResetCompleted, 'push');
        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $push->format);
        self::assertStringContainsString('restablecido', $push->body);
    }

    public function test_template_renderer_config_override_takes_precedence_over_builtin_channel(): void
    {
        $configTemplates = [
            RecoveryNotificationType::PasswordResetRequested->value => [
                'email' => [
                    'subject' => 'Custom email {{reset_url}}',
                    'body' => 'Body {{reset_url}}',
                    'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
                ],
            ],
        ];

        $renderer = new ConfigRecoveryNotificationTemplateRenderer($configTemplates);
        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'email',
            ['reset_url' => 'https://example.com/reset/xyz'],
        );

        self::assertSame('Custom email https://example.com/reset/xyz', $template->subject);
        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $template->format);
    }

    public function test_template_renderer_unknown_channel_falls_back_to_default_builtin(): void
    {
        $renderer = new ConfigRecoveryNotificationTemplateRenderer();

        $template = $renderer->render(
            RecoveryNotificationType::PasswordResetRequested,
            'unknown-channel',
            ['reset_url' => 'https://example.com/reset/xyz'],
        );

        self::assertSame(RecoveryNotificationTemplate::FORMAT_TEXT, $template->format);
        self::assertStringContainsString('Solicitud de restablecimiento', $template->subject);
        self::assertStringContainsString('https://example.com/reset/xyz', $template->body);
    }

    public function test_composite_dispatcher_dispatches_successfully(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register(new NoopRecoveryNotificationChannel());

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: ['default_channel' => 'noop', 'retry' => ['max_attempts' => 1]],
        );

        $result = $dispatcher->dispatch($this->makeNotification('noop'));
        self::assertTrue($result);
    }

    public function test_composite_dispatcher_falls_back_to_default_channel_when_requested_unknown(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register(new NoopRecoveryNotificationChannel());

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: ['default_channel' => 'noop', 'retry' => ['max_attempts' => 1]],
        );

        // Canal solicitado no existe; debe usar el default.
        $result = $dispatcher->dispatch($this->makeNotification('email'));
        self::assertTrue($result);
    }

    public function test_composite_dispatcher_retries_on_failure_and_eventually_succeeds(): void
    {
        // Canal que falla 2 veces y luego tiene éxito.
        $attempts = 0;
        $flaky = new class($attempts) implements RecoveryNotificationChannelInterface {
            private int $calls = 0;

            public function __construct(private int &$attempts) {}

            public function channelName(): string
            {
                return 'flaky';
            }

            public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
            {
                $this->calls++;
                $this->attempts = $this->calls;

                if ($this->calls < 3) {
                    return RecoveryNotificationDeliveryResult::failure(
                        'flaky',
                        $notification->destination,
                        'temporary failure #' . $this->calls,
                        attempt: $this->calls,
                    );
                }

                return RecoveryNotificationDeliveryResult::success(
                    'flaky',
                    $notification->destination,
                    attempt: $this->calls,
                );
            }
        };

        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register($flaky);

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: [
                'default_channel' => 'flaky',
                'retry' => [
                    'max_attempts' => 3,
                    'base_delay_ms' => 0,
                    'max_delay_ms' => 0,
                ],
            ],
        );

        $result = $dispatcher->dispatch($this->makeNotification('flaky'));
        self::assertTrue($result);
        self::assertSame(3, $attempts, 'Debe reintentar hasta el tercer intento');
    }

    public function test_composite_dispatcher_returns_false_after_exhausting_attempts(): void
    {
        $alwaysFail = new class implements RecoveryNotificationChannelInterface {
            public int $calls = 0;

            public function channelName(): string
            {
                return 'always-fail';
            }

            public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
            {
                $this->calls++;

                return RecoveryNotificationDeliveryResult::failure(
                    'always-fail',
                    $notification->destination,
                    'permanent failure',
                    attempt: $this->calls,
                );
            }
        };

        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register($alwaysFail);

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: [
                'default_channel' => 'always-fail',
                'retry' => [
                    'max_attempts' => 3,
                    'base_delay_ms' => 0,
                    'max_delay_ms' => 0,
                ],
            ],
        );

        $result = $dispatcher->dispatch($this->makeNotification('always-fail'));
        self::assertFalse($result);
        self::assertSame(3, $alwaysFail->calls, 'Debe agotar los 3 intentos');
    }

    public function test_composite_dispatcher_uses_fallback_channel_when_primary_fails(): void
    {
        $alwaysFail = new class implements RecoveryNotificationChannelInterface {
            public function channelName(): string
            {
                return 'always-fail';
            }

            public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
            {
                return RecoveryNotificationDeliveryResult::failure(
                    'always-fail',
                    $notification->destination,
                    'permanent failure',
                );
            }
        };

        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register($alwaysFail);
        $registry->register(new NoopRecoveryNotificationChannel());

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: [
                'default_channel' => 'always-fail',
                'fallback_channel' => 'noop',
                'retry' => [
                    'max_attempts' => 1,
                    'base_delay_ms' => 0,
                    'max_delay_ms' => 0,
                ],
            ],
        );

        $result = $dispatcher->dispatch($this->makeNotification('always-fail'));
        self::assertTrue($result, 'Debe tener éxito vía el canal de respaldo');
    }

    public function test_composite_dispatcher_rate_limits_excessive_dispatches(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register(new NoopRecoveryNotificationChannel());

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: [
                'default_channel' => 'noop',
                'retry' => ['max_attempts' => 1, 'base_delay_ms' => 0],
                'rate_limit' => [
                    'max_per_window' => 2,
                    'window_seconds' => 300,
                ],
            ],
        );

        // Los primeros 2 deben tener éxito.
        self::assertTrue($dispatcher->dispatch($this->makeNotification('noop')));
        self::assertTrue($dispatcher->dispatch($this->makeNotification('noop')));

        // El tercero debe ser rate-limited.
        self::assertFalse($dispatcher->dispatch($this->makeNotification('noop')));
    }

    public function test_composite_dispatcher_logs_delivery_audit_on_success(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        $registry->register(new NoopRecoveryNotificationChannel());

        $loggedEvents = [];
        $audit = new class($loggedEvents) implements \Quantum\Auth\Contracts\RecoveryAuditLoggerInterface {
            /** @param list<RecoveryAuditEvent> $loggedEvents */
            public function __construct(private array &$loggedEvents) {}

            public function log(RecoveryAuditEvent $event): bool
            {
                $this->loggedEvents[] = $event;

                return true;
            }

            public function logMany(iterable $events): int
            {
                $count = 0;
                foreach ($events as $event) {
                    $this->log($event);
                    $count++;
                }

                return $count;
            }
        };

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            audit: $audit,
            config: ['default_channel' => 'noop', 'retry' => ['max_attempts' => 1]],
        );

        $dispatcher->dispatch($this->makeNotification('noop', ['token_id' => 'token-abc']));

        self::assertCount(1, $loggedEvents);
        $event = $loggedEvents[0];
        self::assertSame(RecoveryAuditEvent::ACTION_DELIVERY_ATTEMPTED, $event->action);
        self::assertSame('success', $event->result);
        self::assertSame('user@example.com', $event->destination);
        self::assertSame('noop', $event->payload['channel'] ?? null);
        self::assertSame('token-abc', $event->recoveryTokenId);
    }

    public function test_composite_dispatcher_returns_false_when_no_channel_available(): void
    {
        $registry = new RecoveryNotificationChannelRegistry();
        // Sin registrar ningún canal.

        $dispatcher = new CompositeRecoveryNotificationDispatcher(
            registry: $registry,
            config: ['default_channel' => 'nonexistent'],
        );

        $result = $dispatcher->dispatch($this->makeNotification());
        self::assertFalse($result);
    }
}
