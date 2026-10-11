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
     * @var array<string, array<string, array{subject: string, body: string, format: string}>>
     */
    private const BUILTIN_TEMPLATES = [
        RecoveryNotificationType::PasswordResetRequested->value => [
            'default' => [
                'subject' => 'Solicitud de restablecimiento de contraseña',
                'body' => "Hola,\n\nHemos recibido una solicitud para restablecer tu contraseña.\n\nUsa el siguiente enlace: {{reset_url}}\n\nSi no solicitaste esto, ignora este mensaje.",
                'format' => RecoveryNotificationTemplate::FORMAT_TEXT,
            ],
        ],
        RecoveryNotificationType::PasswordResetCompleted->value => [
            'default' => [
                'subject' => 'Tu contraseña ha sido restablecida',
                'body' => "Hola,\n\nTu contraseña se ha restablecido correctamente.\n\nSi no fuiste tú, contacta con soporte inmediatamente.",
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
