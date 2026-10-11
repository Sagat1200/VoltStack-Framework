<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Plantilla renderizada de notificación de recovery.
 *
 * Contiene el asunto, el cuerpo y el formato (texto plano o HTML) listos
 * para ser enviados por un canal concreto.
 */
final readonly class RecoveryNotificationTemplate
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_HTML = 'html';

    public function __construct(
        public string $subject,
        public string $body,
        public string $format = self::FORMAT_TEXT,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'format' => $this->format,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            body: is_string($data['body'] ?? null) ? $data['body'] : '',
            format: is_string($data['format'] ?? null) ? $data['format'] : self::FORMAT_TEXT,
        );
    }
}
