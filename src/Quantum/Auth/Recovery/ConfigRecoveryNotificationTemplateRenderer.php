<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationTemplateRendererInterface;

/**
 * Renderizador de plantillas basado en configuración.
 *
 * Lee las plantillas desde el subtree `auth.recovery.notifications.templates`
 * con la estructura `templates.{type}.{channel}`. Si no hay plantilla configurada,
 * usa plantillas built-in por defecto. Las variables se interpolan con la
 * sintaxis `{{variable_name}}`.
 */
final class ConfigRecoveryNotificationTemplateRenderer implements RecoveryNotificationTemplateRendererInterface
{
    /**
     * Plantillas built-in por defecto: [type][channel] => [subject, body, format].
     *
     * Cada tipo de notificación tiene una plantilla "default" (texto plano) y
     * versiones específicas por canal ("email", "sms", "push") adaptadas al
     * formato y longitud adecuados de cada medio.
     *
     * @var array<string, array<string, array{subject: string, body: string, format: string}>>
     */
    private const BUILTIN_TEMPLATES = [
        RecoveryNotificationType::PasswordResetRequested->value => [
            'default' => [
                'subject' => 'Solicitud de restablecimiento de contraseña',
                'body' => "Hola,\n\nHemos recibido una solicitud para restablecer tu contraseña.\n\nUsa el siguiente enlace: {{reset_url}}\n\nSi no solicitaste esto, ignora este mensaje.",
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
            'email' => [
                'subject' => 'Restablece tu contraseña',
                'body' => <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Restablece tu contraseña</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#333;">
<p>Hola,</p>
<p>Hemos recibido una solicitud para restablecer tu contraseña.</p>
<p>Haz clic en el siguiente enlace para continuar:</p>
<p style="margin:20px 0;">
<a href="{{reset_url}}" style="background-color:#007bff;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block;">Restablecer contraseña</a>
</p>
<p>O copia y pega esta URL en tu navegador:<br>{{reset_url}}</p>
<p>Este enlace expira en {{expires_at}}.</p>
<p>Si no solicitaste este cambio, puedes ignorar este mensaje con seguridad.</p>
<p>Gracias,<br>El equipo de seguridad</p>
</body>
</html>
HTML,
                'format' => RecoveryNotificationTemplate::FORMAT_HTML,
            ],
            'sms' => [
                'subject' => 'Código de restablecimiento',
                'body' => 'Restablece tu contraseña: {{reset_url}} (expira {{expires_at}}). Si no lo solicitaste, ignora este mensaje.',
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
            'push' => [
                'subject' => 'Restablece tu contraseña',
                'body' => 'Toca para restablecer tu contraseña. Expira {{expires_at}}.',
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
        ],
        RecoveryNotificationType::PasswordResetCompleted->value => [
            'default' => [
                'subject' => 'Tu contraseña ha sido restablecida',
                'body' => "Hola,\n\nTu contraseña se ha restablecido correctamente.\n\nSi no fuiste tú, contacta con soporte inmediatamente.",
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
            'email' => [
                'subject' => 'Contraseña restablecida correctamente',
                'body' => <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Contraseña restablecida</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#333;">
<p>Hola,</p>
<p>Tu contraseña se ha restablecido correctamente.</p>
<p>Si realizaste este cambio, no necesitas hacer nada más.</p>
<p><strong>Si no fuiste tú</strong> quien restableció la contraseña, contacta con soporte inmediatamente para proteger tu cuenta.</p>
<p>Gracias,<br>El equipo de seguridad</p>
</body>
</html>
HTML,
                'format' => RecoveryNotificationTemplate::FORMAT_HTML,
            ],
            'sms' => [
                'subject' => 'Contraseña restablecida',
                'body' => 'Tu contraseña se ha restablecido. Si no fuiste tú, contacta con soporte.',
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
            'push' => [
                'subject' => 'Contraseña restablecida',
                'body' => 'Tu contraseña se ha restablecido correctamente.',
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $configTemplates
     */
    public function __construct(
        private readonly array $configTemplates = [],
    ) {}

    public function render(
        RecoveryNotificationType $type,
        string $channel,
        array $variables = [],
    ): RecoveryNotificationTemplate {
        $template = $this->resolveTemplate($type, $channel);

        $subject = $this->interpolate($template['subject'], $variables);
        $body = $this->interpolate($template['body'], $variables);

        return new RecoveryNotificationTemplate(
            subject: $subject,
            body: $body,
            format: $template['format'] ?? RecoveryNotificationTemplate::FORMAT_TEXT,
        );
    }

    /**
     * @return array{subject: string, body: string, format?: string}
     */
    private function resolveTemplate(RecoveryNotificationType $type, string $channel): array
    {
        $typeKey = $type->value;

        // 1) Intenta plantilla específica type+channel en config.
        $configChannel = $this->configTemplates[$typeKey][$channel] ?? null;
        if (is_array($configChannel) && isset($configChannel['subject'], $configChannel['body'])) {
            return $configChannel;
        }

        // 2) Intenta plantilla type+default en config.
        $configDefault = $this->configTemplates[$typeKey]['default'] ?? null;
        if (is_array($configDefault) && isset($configDefault['subject'], $configDefault['body'])) {
            return $configDefault;
        }

        // 3) Built-in específico type+channel.
        $builtinChannel = self::BUILTIN_TEMPLATES[$typeKey][$channel] ?? null;
        if (is_array($builtinChannel) && isset($builtinChannel['subject'], $builtinChannel['body'])) {
            return $builtinChannel;
        }

        // 4) Built-in default por type.
        return self::BUILTIN_TEMPLATES[$typeKey]['default']
            ?? ['subject' => '', 'body' => '', 'format' => RecoveryNotificationTemplate::FORMAT_TEXT];
    }

    /**
     * Interpola variables con sintaxis {{name}} en un texto.
     *
     * @param array<string, mixed> $variables
     */
    private function interpolate(string $text, array $variables): string
    {
        if ($text === '' || $variables === []) {
            return $text;
        }

        $replacements = [];
        foreach ($variables as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $replacements['{{' . $name . '}}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }
}
