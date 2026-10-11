<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;

/**
 * Canal de notificación por correo electrónico.
 *
 * Usa la función nativa `mail()` de PHP (o sendmail si está configurado) para
 * entregar notificaciones de recovery. No depende de librerías externas.
 *
 * El asunto y el cuerpo se obtienen del template renderer (con el canal
 * "email" como clave de plantilla); si no hay renderer disponible, construye
 * un cuerpo básico a partir del payload. Soporta formatos texto plano y HTML.
 */
final class EmailRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    public function __construct(
        private readonly ?RecoveryNotificationTemplateRendererInterface $templateRenderer = null,
        private readonly string $from = '',
        private readonly string $fromName = '',
        private readonly string $transport = 'mail',
    ) {}

    public function channelName(): string
    {
        return 'email';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        $to = trim($notification->destination);

        if ($to === '') {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'email',
                destination: $notification->destination,
                errorMessage: 'email destination is empty',
            );
        }

        try {
            $template = $this->renderTemplate($notification);

            $subject = $template->subject !== '' ? $template->subject : 'Recovery notification';
            $body = $template->body;

            if ($body === '') {
                $body = $this->buildFallbackBody($notification);
            }

            $isHtml = $template->format === RecoveryNotificationTemplate::FORMAT_HTML;

            $headers = $this->buildHeaders($isHtml);

            $success = @mail($to, $subject, $body, $headers);

            if (! $success) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'email',
                    destination: $to,
                    errorMessage: 'mail() returned false',
                );
            }

            return RecoveryNotificationDeliveryResult::success(
                channel: 'email',
                destination: $to,
                providerResponseData: ['transport' => $this->transport, 'format' => $template->format],
            );
        } catch (\Throwable $e) {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'email',
                destination: $to,
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
                    'email',
                    $notification->payload,
                );
            } catch (\Throwable) {
                // Fall through to default empty template.
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
        $lines = [];
        foreach ($notification->payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $lines[] = $key . ': ' . (string) $value;
            }
        }

        return implode("\n", $lines);
    }

    private function buildHeaders(bool $isHtml): string
    {
        $headers = [];

        $from = trim($this->from);
        if ($from !== '') {
            $fromName = trim($this->fromName);
            if ($fromName !== '') {
                $headers[] = 'From: ' . $this->encodeHeader($fromName) . ' <' . $from . '>';
            } else {
                $headers[] = 'From: ' . $from;
            }
        }

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = $isHtml
            ? 'Content-Type: text/html; charset=UTF-8'
            : 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: PHP/' . phpversion();

        return implode("\r\n", $headers);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
