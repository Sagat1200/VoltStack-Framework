<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryNotificationTemplate;
use Quantum\Auth\Recovery\RecoveryNotificationType;

/**
 * Renderizador de plantillas de notificación de recovery.
 *
 * Dado un tipo de notificación, un canal y un conjunto de variables,
 * produce una plantilla lista para enviar. Permite centralizar los textos
 * y hacerlos configurables por canal.
 */
interface RecoveryNotificationTemplateRendererInterface
{
    /**
     * @param array<string, mixed> $variables
     */
    public function render(
        RecoveryNotificationType $type,
        string $channel,
        array $variables = [],
    ): RecoveryNotificationTemplate;
}
